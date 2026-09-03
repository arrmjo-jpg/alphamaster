<?php

declare(strict_types=1);

namespace App\Modules\Integration\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Integration\Requests\UpdateIntegrationProviderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class IntegrationProviderAdminController extends BaseApiController
{
    /**
     * List configured providers.
     */
    public function index(): JsonResponse
    {
        return $this->successResponse(
            IntegrationProvider::query()
                ->orderBy('capability')
                ->orderByDesc('is_default')
                ->orderBy('priority')
                ->get()
                ->map(fn (IntegrationProvider $p): array => $this->present($p))
                ->all()
        );
    }

    /**
     * Update a provider: its label, non-secret settings, activation, failover
     * position, and optionally its credentials.
     */
    public function update(UpdateIntegrationProviderRequest $request, IntegrationProvider $provider): JsonResponse
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($validated, $provider): JsonResponse {
            $provider->fill([
                'label' => $validated['label'] ?? $provider->label,
                'settings' => $validated['settings'] ?? $provider->settings,
                'is_active' => $validated['is_active'] ?? $provider->is_active,
                'priority' => $validated['priority'] ?? $provider->priority,
            ]);

            // Credentials are write-only: omitting the key leaves the stored secret
            // untouched, and sending null clears it. They are never read back out.
            if (array_key_exists('credentials', $validated)) {
                $provider->setCredentials($validated['credentials']);
            }

            $provider->save();

            return $this->successResponse($this->present($provider->refresh()), 'Provider updated.');
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
        if (! $provider->is_active) {
            return $this->errorResponse(
                'PROVIDER_INACTIVE',
                'An inactive provider cannot be made the default.',
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

        return $this->successResponse($this->present($provider->refresh()), 'Default provider updated.');
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

    /**
     * Provider representation.
     *
     * Credentials are reported only as present or absent. The values never leave the
     * server once stored, so a compromised admin session cannot read them back.
     *
     * @return array<string, mixed>
     */
    private function present(IntegrationProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'capability' => $provider->capability->value,
            'driver' => $provider->driver,
            'label' => $provider->label,
            'settings' => $provider->settings,
            'has_credentials' => $provider->hasCredentials(),
            'is_active' => $provider->is_active,
            'is_default' => $provider->is_default,
            'priority' => $provider->priority,
            'updated_at' => $provider->updated_at?->toIso8601String(),
        ];
    }
}
