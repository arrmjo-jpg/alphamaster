<?php

declare(strict_types=1);

use App\Modules\Localization\Controllers\Admin\LanguageAdminController;
use App\Modules\Localization\Controllers\Api\LanguageApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public localization routes
    Route::get('/languages', [LanguageApiController::class, 'index'])->name('api.languages.index');

    // Admin language management routes (protected by admin perimeter)
    Route::prefix('admin/languages')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin'])
        ->group(function (): void {
            Route::get('/', [LanguageAdminController::class, 'index'])->name('admin.languages.index');
            Route::post('/', [LanguageAdminController::class, 'store'])->name('admin.languages.store');
            Route::get('/{id}', [LanguageAdminController::class, 'show'])->name('admin.languages.show');
            Route::put('/{id}', [LanguageAdminController::class, 'update'])->name('admin.languages.update');
            Route::patch('/{id}/status', [LanguageAdminController::class, 'toggleStatus'])->name('admin.languages.status');
            Route::patch('/{id}/default', [LanguageAdminController::class, 'setDefault'])->name('admin.languages.default');
        });
});
