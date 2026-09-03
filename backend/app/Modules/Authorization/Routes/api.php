<?php

declare(strict_types=1);

use App\Modules\Authorization\Controllers\Admin\RoleAdminController;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\User\Controllers\Admin\UserAdminController;
use Illuminate\Support\Facades\Route;

/**
 * Administrative endpoints sit behind the full stack established across phases:
 *
 *   auth:sanctum          the token resolves to a user
 *   ability:admin:access  the token itself is administrative (ADR 0012)
 *   active                the account is not suspended
 *   admin                 the account type is admin
 *   permission:<name>     and the administrator holds this specific permission
 *
 * The last stage never replaces the ones before it. Being an administrator does not
 * imply any particular permission, and holding a permission does not make an account
 * an administrator.
 */
Route::prefix('v1/admin')
    ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin'])
    ->group(function (): void {
        Route::get('/users', [UserAdminController::class, 'index'])
            ->middleware('permission:'.AdminPermission::USERS_VIEW->value)
            ->name('admin.users.index');

        Route::get('/users/{user}', [UserAdminController::class, 'show'])
            ->middleware('permission:'.AdminPermission::USERS_VIEW->value)
            ->name('admin.users.show');

        Route::post('/users/{user}/promote', [UserAdminController::class, 'promote'])
            ->middleware('permission:'.AdminPermission::USERS_UPDATE->value)
            ->name('admin.users.promote');

        Route::post('/users/{user}/demote', [UserAdminController::class, 'demote'])
            ->middleware('permission:'.AdminPermission::USERS_UPDATE->value)
            ->name('admin.users.demote');

        Route::put('/users/{user}/roles', [UserAdminController::class, 'syncRoles'])
            ->middleware('permission:'.AdminPermission::ROLES_UPDATE->value)
            ->name('admin.users.roles.sync');

        Route::get('/roles', [RoleAdminController::class, 'index'])
            ->middleware('permission:'.AdminPermission::ROLES_VIEW->value)
            ->name('admin.roles.index');

        Route::post('/roles', [RoleAdminController::class, 'store'])
            ->middleware('permission:'.AdminPermission::ROLES_UPDATE->value)
            ->name('admin.roles.store');

        Route::put('/roles/{role}', [RoleAdminController::class, 'update'])
            ->middleware('permission:'.AdminPermission::ROLES_UPDATE->value)
            ->name('admin.roles.update');

        Route::delete('/roles/{role}', [RoleAdminController::class, 'destroy'])
            ->middleware('permission:'.AdminPermission::ROLES_UPDATE->value)
            ->name('admin.roles.destroy');

        Route::get('/permissions', [RoleAdminController::class, 'permissions'])
            ->middleware('permission:'.AdminPermission::PERMISSIONS_VIEW->value)
            ->name('admin.permissions.index');
    });
