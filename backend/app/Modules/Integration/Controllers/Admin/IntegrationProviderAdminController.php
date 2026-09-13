<?php

declare(strict_types=1);

namespace App\Modules\Integration\Controllers\Admin;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Integration\Data\ServiceAccount;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Integration\Requests\UpdateIntegrationProviderRequest;
use App\Modules\Integration\Resources\IntegrationProviderResource;
use App\Modules\Integration\Services\SocialLoginGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class IntegrationProviderAdminController extends BaseApiController
{
    public function __construct(
        protected AuditRecorderContract $audit
    ) {}

    /**
     * List configured providers.
     */
    public function index(): JsonResponse
    {
        return $this->successResponse(
            IntegrationProviderResource::collection(
                IntegrationProvider::query()
                    ->orderBy('capability')
                    ->orderByDesc('is_default')
                    ->orderBy('priority')
                    ->get()
            )
        );
    }

    /**
     * Update a provider: its label, non-secret settings, activation, failover
     * position, and optionally its credentials.
     */
    public function update(UpdateIntegrationProviderRequest $request, IntegrationProvider $provider, SocialLoginGateway $socialLogin): JsonResponse
    {
        if ($provider->capability === IntegrationCapability::AI) {
            return $this->configuredInAiControlCentre();
        }

        $validated = $request->validated();

        return DB::transaction(function () use ($validated, $provider, $socialLogin): JsonResponse {
            $provider->fill([
                'label' => $validated['label'] ?? $provider->label,
                'settings' => $validated['settings'] ?? $provider->settings,
                'is_active' => $validated['is_active'] ?? $provider->is_active,
                'priority' => $validated['priority'] ?? $provider->priority,
            ]);

            // Read before the credentials are touched, so the column holding them can
            // never be named among the fields that changed.
            $fields = array_values(array_diff(array_keys($provider->getDirty()), ['credentials']));

            // Credentials are write-only: omitting the key leaves the stored secret
            // untouched, and sending null clears it. They are never read back out.
            // The same vocabulary the AI control centre records a key with.
            $credentials = 'unchanged';

            // A pasted service-account file becomes the three values the driver reads,
            // and the file itself is not kept (ADR 0045 §2). It is a replacement, like
            // every credential write; removal stays `credentials: null`, its own act.
            $serviceAccount = array_key_exists('service_account_json', $validated)
                ? ServiceAccount::parse((string) $validated['service_account_json'])
                : null;

            if ($serviceAccount instanceof ServiceAccount || array_key_exists('credentials', $validated)) {
                $hadCredentials = $provider->hasCredentials();
                $provider->setCredentials(
                    $serviceAccount instanceof ServiceAccount ? $serviceAccount->credentials() : $validated['credentials']
                );
                $credentials = match (true) {
                    ! $provider->hasCredentials() => 'cleared',
                    $hadCredentials => 'replaced',
                    default => 'set',
                };
            }

            // A social login provider declares its required configuration, and one missing
            // any of it cannot be on (ADR 0050 §10, ADR 0038) — whether this request
            // switches it on or removes a field from one that already is. Judged on the
            // row as this request would leave it, and refused before anything is written.
            // The refusal names fields, never values.
            if ($provider->capability === IntegrationCapability::SOCIAL_LOGIN && $provider->is_active) {
                $missing = $socialLogin->missingConfiguration($provider);

                if ($missing !== []) {
                    return $this->errorResponse(
                        'PROVIDER_CONFIGURATION_INCOMPLETE',
                        'api.error.integration.provider_configuration_incomplete',
                        ['missing' => $missing],
                        422
                    );
                }
            }

            $provider->save();

            // A key rotation is the change an operator most needs to find afterwards,
            // and until now it left no trace at all. What is recorded is that the
            // credentials were set, replaced or cleared, and which other fields changed
            // — by name. Never a value, and nothing derived from one (ADR 0037).
            if ($fields !== [] || $credentials !== 'unchanged') {
                $this->audit->succeeded(AuditAction::INTEGRATION_PROVIDER_UPDATED, $provider->id, [
                    'capability' => $provider->capability->value,
                    'driver' => $provider->driver,
                    'fields' => $fields,
                    'credentials' => $credentials,
                ]);
            }

            return $this->successResponse(new IntegrationProviderResource($provider->refresh()), 'Provider updated.');
        });
    }

    /**
     * Make this provider the default for its capability.
     *
     * Demoting the incumbent and promoting the successor happen in one transaction,
     * because the partial unique index permits only one default per capability and a
     * half-applied swap would leave none.
     */
    public function makeDefault(IntegrationProvider $provider): JsonResponse
    {
        if ($provider->capability === IntegrationCapability::AI) {
            return $this->configuredInAiControlCentre();
        }

        if (! $provider->is_active) {
            return $this->errorResponse(
                'PROVIDER_INACTIVE',
                'api.error.integration.provider_inactive',
                null,
                422
            );
        }

        DB::transaction(function () use ($provider): void {
            IntegrationProvider::query()
                ->forCapability($provider->capability)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $provider->forceFill(['is_default' => true])->save();
        });

        return $this->successResponse(new IntegrationProviderResource($provider->refresh()), 'Default provider updated.');
    }

    /**
     * AI providers have one setup form — provider, API key, model — in the AI control
     * centre. The generic editor here takes free-form credential names and a failover
     * priority, neither of which means anything for AI, and a second way to write the
     * same row is a second way to leave it half configured.
     */
    private function configuredInAiControlCentre(): JsonResponse
    {
        return $this->errorResponse(
            'AI_CONFIGURED_IN_CONTROL_CENTRE',
            'api.error.integration.ai_managed_elsewhere',
            null,
            422
        );
    }

    /**
     * Recent usage, for diagnosing a failing vendor.
     */
    public function usage(): JsonResponse
    {
        $logs = IntegrationUsageLog::query()
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (IntegrationUsageLog $log): array => [
                'id' => $log->id,
                'capability' => $log->capability->value,
                'driver' => $log->driver,
                'status' => $log->status->value,
                'reference' => $log->reference,
                'error_code' => $log->error_code,
                'error_message' => $log->error_message,
                'duration_ms' => $log->duration_ms,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();

        return $this->successResponse($logs);
    }
}
