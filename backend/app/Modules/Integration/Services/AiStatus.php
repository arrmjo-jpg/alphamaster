<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;

/**
 * What an operator needs to know about AI before they trust it, and nothing more.
 *
 * The three questions a control centre has to separate, because an interface that
 * runs them together sends somebody to fix the wrong thing:
 *
 *   * **Is a vendor selected?** A provider row marked default and active.
 *   * **Does it hold a credential?** A boolean, derived from whether ciphertext exists.
 *     Never the credential, never its length, never a masked form of it — the API has
 *     never read one back and this is not the place to start (ADR 0017).
 *   * **Does it answer?** Only knowable by asking, so it is reported from the last
 *     attempt rather than guessed, and the control centre offers a way to ask.
 *
 * The last attempt is read from the usage log rather than remembered separately. That
 * log already records every call with its outcome and duration, so a second store of
 * the same fact would be a second thing to keep in step.
 */
class AiStatus
{
    public function __construct(private readonly AiManager $manager) {}

    /**
     * @return array{
     *     configured: bool,
     *     provider: array{driver: string, label: string, has_credentials: bool}|null,
     *     available_drivers: array<int, string>,
     *     last_attempt: array{status: string, at: string, error_code: string|null, error_message: string|null, duration_ms: int|null, units: int|null}|null,
     *     recent_failures: int
     * }
     */
    public function describe(): array
    {
        $provider = $this->manager->defaultProvider();
        $last = $this->lastAttempt();

        return [
            // Configured means a request would reach a vendor. It says nothing about
            // whether the vendor would answer, which is the next field's job.
            'configured' => $provider !== null && $provider->hasCredentials(),

            'provider' => $provider === null ? null : [
                'driver' => $provider->driver,
                'label' => $provider->label,
                'has_credentials' => $provider->hasCredentials(),
            ],

            // Every driver the platform can speak to, whether or not a row exists for
            // it. An operator choosing a vendor should see what is possible rather than
            // only what somebody has already created.
            'available_drivers' => $this->availableDrivers(),

            'last_attempt' => $last === null ? null : [
                'status' => $last->status->value,
                'at' => $last->created_at?->toIso8601String() ?? '',
                'error_code' => $last->error_code,
                // The vendor's own sentence. Safe: a usage log never holds a prompt or
                // an answer, so the worst it can carry is a vendor's error text.
                'error_message' => $last->error_message,
                'duration_ms' => $last->duration_ms,
                'units' => $last->units,
            ],

            // Enough to distinguish "it failed once" from "it has been failing", which
            // is the difference between a blip and a configuration that is wrong.
            'recent_failures' => $this->recentFailures(),
        ];
    }

    /**
     * The most recent AI attempt, whatever its outcome.
     */
    private function lastAttempt(): ?IntegrationUsageLog
    {
        /** @var IntegrationUsageLog|null $log */
        $log = IntegrationUsageLog::query()
            ->where('capability', $this->manager->capability()->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $log;
    }

    private function recentFailures(): int
    {
        return IntegrationUsageLog::query()
            ->where('capability', $this->manager->capability()->value)
            ->where('status', UsageStatus::FAILURE->value)
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    /**
     * @return array<int, string>
     */
    private function availableDrivers(): array
    {
        $drivers = IntegrationProvider::query()
            ->forCapability($this->manager->capability())
            ->orderBy('priority')
            ->pluck('driver')
            ->all();

        return array_values(array_unique(array_map('strval', $drivers)));
    }
}
