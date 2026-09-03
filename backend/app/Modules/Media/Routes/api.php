<?php

declare(strict_types=1);

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Media\Controllers\Admin\MediaAdminController;
use App\Modules\Media\Controllers\Api\MediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Media is a platform capability, so any fully signed-in account may upload and
    // read what it is entitled to. Abilities are named literally rather than through
    // the Auth enum, matching every other module: architecture rules analyse classes,
    // and a route file is not one.
    Route::middleware(['auth:sanctum', 'ability:admin:access,user:access', 'active'])
        ->group(function (): void {
            Route::post('/media', [MediaController::class, 'store'])->name('api.media.store');
            Route::get('/media/{media}', [MediaController::class, 'show'])->name('api.media.show');
        });

    // Moderation is administrative, behind the full five-stage stack.
    Route::prefix('admin/media')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin'])
        ->group(function (): void {
            Route::get('/', [MediaAdminController::class, 'index'])
                ->middleware('permission:'.AdminPermission::MEDIA_VIEW->value)
                ->name('admin.media.index');

            Route::get('/{media}', [MediaAdminController::class, 'show'])
                ->middleware('permission:'.AdminPermission::MEDIA_VIEW->value)
                ->name('admin.media.show');

            Route::delete('/{media}', [MediaAdminController::class, 'destroy'])
                ->middleware('permission:'.AdminPermission::MEDIA_DELETE->value)
                ->name('admin.media.destroy');
        });
});
