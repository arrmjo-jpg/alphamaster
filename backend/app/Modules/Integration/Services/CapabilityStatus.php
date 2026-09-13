<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;

/**
 * What an operator needs to know about one capability before they trust it.
 *
 * Written for AI first and generalised when push needed exactly the same three
 * answers, kept apart because an interface that runs them together sends somebody to
 * fix the wrong thing:
 *
 *   * **Is a vendor selected?** The provider row marked default and active.
 *   * **Does it hold a credential?** A boolean, derived from whether ciphertext exists.
 *     Never the credential, never its length, never a masked form of it — the API has
 *     never read one back (ADR 0017).
 *   * **Does it answer?** Only knowable by asking, so it is reported from the last
 *     attempt in the usage log rather than guessed or remembered separately.
 */
class CapabilityStatus
{
    /**
     * @return array{
     *     configured: bool,
     *     provider: array{driver: string, label: string, has_credentials: bool}|null,
     *     available_drivers: array<int, string>,
     *     last_attempt: array{status: string, at: string, error_code: string|null, error_message: string|null, duration_ms: int|null, units: int|null}|null,
     *     recent_failures: int
     * }
     */
    public function describe(ProviderManager $manager): array
    {
        $capability = $manager->capability()->value;
        $provider = $manager->defaultProvider();

        /** @var IntegrationUsageLog|null $last */
        $last = IntegrationUsageLog::query()
            ->where('capability', $capability)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return [
            // Configured means a request would reach a vendor. It says nothing about
            // whether the vendor would answer, which is the last attempt's job.
            'configured' => $provider !== null && $provider->hasCredentials(),

            'provider' => $provider === null ? null : [
                'driver' => $provider->driver,
                'label' => $provider->label,
                'has_credentials' => $provider->hasCredentials(),
            ],

            // What is possible rather than only what somebody has already switched on:
            // an operator choosing a vendor should see the choice.
            'available_drivers' => array_values(array_unique(array_map(
                'strval',
                IntegrationProvider::query()
                    ->where('capability', $capability)
                    ->orderBy('priority')
                    ->pluck('driver')
                    ->all()
            ))),

            'last_attempt' => $last === null ? null : [
                'status' => $last->status->value,
                'at' => $last->created_at?->toIso8601String() ?? '',
                'error_code' => $last->error_code,
                // The vendor's own sentence. Safe: a usage log never holds a prompt, a
                // message body, a recipient or a device token, so the worst it can
                // carry is a vendor's error text.
                'error_message' => $last->error_message,
                'duration_ms' => $last->duration_ms,
                'units' => $last->units,
            ],

            // Enough to tell "it failed once" from "it has been failing".
            'recent_failures' => IntegrationUsageLog::query()
                ->where('capability', $capability)
                ->where('status', UsageStatus::FAILURE->value)
                ->where('created_at', '>=', now()->subDay())
                ->count(),
        ];
    }
}
