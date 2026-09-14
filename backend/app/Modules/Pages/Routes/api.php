<?php

declare(strict_types=1);

use App\Modules\Pages\Controllers\Admin\PageAdminController;
use App\Modules\Pages\Controllers\Api\PageController;
use App\Modules\Pages\Enums\PagePermission;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public pages (ADR 0055 §5). The language is required, so it is always in the address,
    // and the edge may store these; a page's change purges `pages:{id}` and `pages:list`.
    Route::get('/pages', [PageController::class, 'index'])
        ->middleware('http.cache:pages')
        ->name('api.pages.index');
    Route::get('/pages/{slug}', [PageController::class, 'show'])
        ->middleware('http.cache:pages')
        ->name('api.pages.show');

    // Administration. Permissions come from this module's own enum (ADR 0052), not literals.
    Route::prefix('admin/pages')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
        ->group(function (): void {
            Route::get('/', [PageAdminController::class, 'index'])
                ->middleware('permission:'.PagePermission::VIEW->value)
                ->name('admin.pages.index');
            Route::post('/', [PageAdminController::class, 'store'])
                ->middleware('permission:'.PagePermission::CREATE->value)
                ->name('admin.pages.store');
            Route::get('/{page}', [PageAdminController::class, 'show'])
                ->middleware('permission:'.PagePermission::VIEW->value)
                ->name('admin.pages.show');
            Route::patch('/{page}', [PageAdminController::class, 'update'])
                ->middleware('permission:'.PagePermission::UPDATE->value)
                ->name('admin.pages.update');
            Route::put('/{page}/translations/{locale}', [PageAdminController::class, 'writeTranslation'])
                ->middleware('permission:'.PagePermission::UPDATE->value)
                ->name('admin.pages.translations.write');
            Route::post('/{page}/publish', [PageAdminController::class, 'publish'])
                ->middleware('permission:'.PagePermission::PUBLISH->value)
                ->name('admin.pages.publish');
            Route::post('/{page}/unpublish', [PageAdminController::class, 'unpublish'])
                ->middleware('permission:'.PagePermission::PUBLISH->value)
                ->name('admin.pages.unpublish');
            Route::post('/{page}/archive', [PageAdminController::class, 'archive'])
                ->middleware('permission:'.PagePermission::PUBLISH->value)
                ->name('admin.pages.archive');
            Route::delete('/{page}', [PageAdminController::class, 'destroy'])
                ->middleware('permission:'.PagePermission::DELETE->value)
                ->name('admin.pages.destroy');
        });
});
