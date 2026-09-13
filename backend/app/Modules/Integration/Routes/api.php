<?php

declare(strict_types=1);

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Integration\Controllers\Admin\AiAdminController;
use App\Modules\Integration\Controllers\Admin\IntegrationProviderAdminController;
use Illuminate\Support\Facades\Route;

/**
 * Vendor configuration is administrative: the same five-stage stack as every other
 * admin route, with its own permissions so that reading which vendors exist and
 * changing their credentials are separately grantable.
 */
Route::prefix('v1/admin/integrations')
    ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
    ->group(function (): void {
        Route::get('/providers', [IntegrationProviderAdminController::class, 'index'])
            ->middleware('permission:'.AdminPermission::INTEGRATIONS_VIEW->value)
            ->name('admin.integrations.providers.index');

        Route::get('/usage', [IntegrationProviderAdminController::class, 'usage'])
            ->middleware('permission:'.AdminPermission::INTEGRATIONS_VIEW->value)
            ->name('admin.integrations.usage');

        Route::put('/providers/{provider}', [IntegrationProviderAdminController::class, 'update'])
            ->middleware('permission:'.AdminPermission::INTEGRATIONS_UPDATE->value)
            ->name('admin.integrations.providers.update');

        Route::post('/providers/{provider}/default', [IntegrationProviderAdminController::class, 'makeDefault'])
            ->middleware('permission:'.AdminPermission::INTEGRATIONS_UPDATE->value)
            ->name('admin.integrations.providers.default');
    });

/**
 * The AI control centre (ADR 0044).
 *
 * Under its own prefix rather than inside `integrations`, because it is not a view of
 * the provider table: it answers what the platform can currently do, which is a
 * question about the vendor, the credential, the model setting and the last attempt
 * together.
 *
 * Reading is `integrations.view` — it describes a vendor. Running a check is `ai.use`,
 * because a check is a real call with a real cost, and the permission that governs
 * spending is the one that should govern it.
 */
Route::prefix('v1/admin/ai')
    ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
    ->group(function (): void {
        Route::get('/', [AiAdminController::class, 'show'])
            ->middleware('permission:'.AdminPermission::INTEGRATIONS_VIEW->value)
            ->name('admin.ai.show');

        Route::post('/check', [AiAdminController::class, 'check'])
            ->middleware('permission:'.AdminPermission::AI_USE->value)
            ->name('admin.ai.check');

        // Setting up a provider is configuring a vendor, so it is the permission that
        // changes integrations. Each provider keeps its own key and model; saving one
        // never touches another.
        Route::put('/providers/{provider}', [AiAdminController::class, 'save'])
            ->middleware('permission:'.AdminPermission::INTEGRATIONS_UPDATE->value)
            ->name('admin.ai.providers.save');

        Route::delete('/providers/{provider}/key', [AiAdminController::class, 'removeKey'])
            ->middleware('permission:'.AdminPermission::INTEGRATIONS_UPDATE->value)
            ->name('admin.ai.providers.key');

        Route::post('/providers/{provider}/default', [AiAdminController::class, 'makeDefault'])
            ->middleware('permission:'.AdminPermission::INTEGRATIONS_UPDATE->value)
            ->name('admin.ai.providers.default');
    });
