<?php

declare(strict_types=1);

use App\Modules\Authorization\Enums\AdminPermission;
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

    // Admin Settings Endpoints (Protected by the full Sanctum admin perimeter, then
    // the specific permission each route requires). The perimeter establishes that a
    // caller is an authenticated, active administrator; it never establishes which
    // settings they may read or change. `settings.view` and `settings.update` have
    // been in the catalogue and on the seeded roles since Phase 6 — this is where
    // they are enforced, in the same per-route form every other admin module uses.
    Route::prefix('admin/settings')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin'])
        ->group(function () use ($groupPattern): void {
            Route::get('/', [SettingAdminController::class, 'index'])
                ->middleware('permission:'.AdminPermission::SETTINGS_VIEW->value)
                ->name('admin.settings.index');
            Route::get('/{group}', [SettingAdminController::class, 'show'])
                ->middleware('permission:'.AdminPermission::SETTINGS_VIEW->value)
                ->where('group', $groupPattern)
                ->name('admin.settings.show');
            // Updating requires the update permission alone: an administrator who may
            // change a value can necessarily read the group they are changing, and
            // requiring both would let a role hold `settings.update` and still be
            // refused, which is not a state the seeded roles can express.
            Route::put('/{group}', [SettingAdminController::class, 'update'])
                ->middleware('permission:'.AdminPermission::SETTINGS_UPDATE->value)
                ->where('group', $groupPattern)
                ->name('admin.settings.update');
        });
});
