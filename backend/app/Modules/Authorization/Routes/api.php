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
    ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
    ->group(function (): void {
        Route::get('/users', [UserAdminController::class, 'index'])
            ->middleware('permission:'.AdminPermission::USERS_VIEW->value)
            ->name('admin.users.index');

        // `users.create` has been in the catalogue and on the seeded administrator
        // role since Phase 6 with nothing serving it. It creates a regular account:
        // promotion remains the only route across the administrative boundary and
        // needs `users.update`, so creating an administrator still takes both.
        Route::post('/users', [UserAdminController::class, 'store'])
            ->middleware('permission:'.AdminPermission::USERS_CREATE->value)
            ->name('admin.users.store');

        Route::get('/users/{user}', [UserAdminController::class, 'show'])
            ->middleware('permission:'.AdminPermission::USERS_VIEW->value)
            ->name('admin.users.show');

        // Identity only. Standing, roles and activation each have their own operation
        // below, so this one cannot change what an account may do.
        Route::put('/users/{user}', [UserAdminController::class, 'update'])
            ->middleware('permission:'.AdminPermission::USERS_UPDATE->value)
            ->name('admin.users.update');

        // Two operations rather than one toggle: a toggle decides from state the
        // caller read a moment ago, and the moment worth being sure about is the one
        // where an account stops being able to sign in.
        Route::post('/users/{user}/activate', [UserAdminController::class, 'activate'])
            ->middleware('permission:'.AdminPermission::USERS_UPDATE->value)
            ->name('admin.users.activate');

        Route::post('/users/{user}/deactivate', [UserAdminController::class, 'deactivate'])
            ->middleware('permission:'.AdminPermission::USERS_UPDATE->value)
            ->name('admin.users.deactivate');

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
