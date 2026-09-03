<?php

declare(strict_types=1);

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Integration\Controllers\Admin\IntegrationProviderAdminController;
use Illuminate\Support\Facades\Route;

/**
 * Vendor configuration is administrative: the same five-stage stack as every other
 * admin route, with its own permissions so that reading which vendors exist and
 * changing their credentials are separately grantable.
 */
Route::prefix('v1/admin/integrations')
    ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin'])
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
