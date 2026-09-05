<?php

declare(strict_types=1);

namespace App\Modules\Integration\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Integration\Requests\UpdateIntegrationProviderRequest;
use App\Modules\Integration\Resources\IntegrationProviderResource;
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
