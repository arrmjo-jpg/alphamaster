<?php

declare(strict_types=1);

use App\Modules\Settings\Controllers\Admin\SettingAdminController;
use App\Modules\Settings\Controllers\Api\SettingApiController;
use Illuminate\Support\Facades\Route;

/**
 * Group names are constrained at the route level so that an arbitrary path segment can
 * never reach the service layer and be used as a cache key.
 */
$groupPattern = '[a-z][a-z0-9_]{0,49}';

Route::prefix('v1')->group(function () use ($groupPattern): void {
    // Public Settings Endpoints (Minimal payload, zero secrets, zero internal flags)
    Route::get('/settings', [SettingApiController::class, 'index'])->name('api.settings.index');
    Route::get('/settings/{group}', [SettingApiController::class, 'show'])
        ->where('group', $groupPattern)
        ->name('api.settings.show');

    // Admin Settings Endpoints (Protected by full 4-stage Sanctum admin perimeter)
    Route::prefix('admin/settings')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin'])
        ->group(function () use ($groupPattern): void {
            Route::get('/', [SettingAdminController::class, 'index'])->name('admin.settings.index');
            Route::get('/{group}', [SettingAdminController::class, 'show'])
                ->where('group', $groupPattern)
                ->name('admin.settings.show');
            Route::put('/{group}', [SettingAdminController::class, 'update'])
                ->where('group', $groupPattern)
                ->name('admin.settings.update');
        });
});
