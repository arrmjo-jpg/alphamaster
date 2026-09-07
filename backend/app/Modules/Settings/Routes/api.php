<?php

declare(strict_types=1);

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Settings\Controllers\Admin\SecretAdminController;
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

            // Declared before the {group} route: the group pattern would otherwise
            // match "definitions" and answer 404 for a group nobody named.
            Route::get('/definitions', [SettingAdminController::class, 'definitions'])
                ->middleware('permission:'.AdminPermission::SETTINGS_VIEW->value)
                ->name('admin.settings.definitions');

            // An operational action rather than a read, and it sends real mail, so it
            // needs the permission that changes configuration rather than the one that
            // looks at it.
            Route::post('/mail/test', [SettingAdminController::class, 'testMail'])
                ->middleware('permission:'.AdminPermission::SETTINGS_UPDATE->value)
                ->name('admin.settings.mail.test');
            Route::get('/{group}', [SettingAdminController::class, 'show'])
                ->middleware('permission:'.AdminPermission::SETTINGS_VIEW->value)
                ->where('group', $groupPattern)
                ->name('admin.settings.show');
            // Updating requires the update permission alone: an administrator who may
            // change a value can necessarily read the group they are changing, and
            // requiring both would let a role hold `settings.update` and still be
            // refused, which is not a state the seeded roles can express.
            // A deeper path than /{group}, so the group pattern does not swallow it.
            Route::get('/{group}/history', [SettingAdminController::class, 'history'])
                ->middleware('permission:'.AdminPermission::SETTINGS_VIEW->value)
                ->where('group', $groupPattern)
                ->name('admin.settings.history');

            // Rotation needs the permission every write to a secret needs, and no
            // more: it is the same class of change, performed more carefully
            // (ADR 0038). Declared before /{group} so the group pattern cannot
            // swallow the deeper path.
            Route::post('/{group}/secrets/{key}/rotate', [SecretAdminController::class, 'rotate'])
                ->middleware('permission:'.AdminPermission::SETTINGS_SECRETS_MANAGE->value)
                ->where('group', $groupPattern)
                ->where('key', '[a-z][a-z0-9_]{0,49}')
                ->name('admin.settings.secrets.rotate');

            // Its own permission, not `settings.update`: a rollback changes many
            // values at once, from a state the operator may not have inspected, and it
            // is the operation most likely to be run under pressure (ADR 0040). The
            // settings it may actually touch are checked per key against the computed
            // plan, so this route cannot be used to change a guarded value without the
            // permission that guards it.
            Route::post('/{group}/rollback', [SettingAdminController::class, 'rollback'])
                ->middleware('permission:'.AdminPermission::SETTINGS_ROLLBACK->value)
                ->where('group', $groupPattern)
                ->name('admin.settings.rollback');

            Route::put('/{group}', [SettingAdminController::class, 'update'])
                ->middleware('permission:'.AdminPermission::SETTINGS_UPDATE->value)
                ->where('group', $groupPattern)
                ->name('admin.settings.update');
        });
});
