<?php

declare(strict_types=1);

use App\Modules\Settings\Controllers\Admin\SettingAdminController;
use App\Modules\Settings\Controllers\Api\SettingApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public Settings Endpoints (Minimal payload, zero secrets, zero internal flags)
    Route::get('/settings', [SettingApiController::class, 'index'])->name('api.settings.index');
    Route::get('/settings/{group}', [SettingApiController::class, 'show'])->name('api.settings.show');

    // Admin Settings Endpoints (Protected by full 4-stage Sanctum admin perimeter)
    Route::prefix('admin/settings')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin'])
        ->group(function (): void {
            Route::get('/', [SettingAdminController::class, 'index'])->name('admin.settings.index');
            Route::get('/{group}', [SettingAdminController::class, 'show'])->name('admin.settings.show');
            Route::put('/{group}', [SettingAdminController::class, 'update'])->name('admin.settings.update');
        });
});
