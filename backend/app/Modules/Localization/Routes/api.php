<?php

declare(strict_types=1);

use App\Modules\Localization\Controllers\Admin\LanguageAdminController;
use App\Modules\Localization\Controllers\Admin\TranslationWorkshopController;
use App\Modules\Localization\Controllers\Api\LanguageApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public localization routes
    Route::get('/languages', [LanguageApiController::class, 'index'])->name('api.languages.index');

    // Admin language management routes (protected by admin perimeter)
    Route::prefix('admin/languages')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
        ->group(function (): void {
            Route::get('/', [LanguageAdminController::class, 'index'])->name('admin.languages.index');
            Route::post('/', [LanguageAdminController::class, 'store'])->name('admin.languages.store');
            Route::get('/{id}', [LanguageAdminController::class, 'show'])->name('admin.languages.show');
            Route::put('/{id}', [LanguageAdminController::class, 'update'])->name('admin.languages.update');
            Route::patch('/{id}/status', [LanguageAdminController::class, 'toggleStatus'])->name('admin.languages.status');
            Route::patch('/{id}/default', [LanguageAdminController::class, 'setDefault'])->name('admin.languages.default');
        });

    // The translation workshop: what is written in each of those languages.
    //
    // No permission on the routes, and that is not an omission. One endpoint serves
    // several bodies of content owned by different modules, so "may this caller?" has
    // a different answer per source — role labels need `roles.update`, notification
    // wording needs `notifications.update` — and a single middleware could only ever
    // ask one of those questions. The controller asks the owning module's (ADR 0043).
    Route::prefix('admin/translations')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
        ->group(function (): void {
            Route::get('/', [TranslationWorkshopController::class, 'index'])
                ->name('admin.translations.index');

            Route::put('/{source}/{id}', [TranslationWorkshopController::class, 'update'])
                ->name('admin.translations.update');
        });
});
