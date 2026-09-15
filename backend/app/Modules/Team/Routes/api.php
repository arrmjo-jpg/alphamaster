<?php

declare(strict_types=1);

use App\Modules\Team\Controllers\Admin\TeamMemberAdminController;
use App\Modules\Team\Controllers\Api\TeamController;
use App\Modules\Team\Enums\TeamPermission;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // The public team directory (ADR 0055 §5). The language is required, so the edge may
    // store these; a change purges `team:{id}` and `team:list`.
    Route::get('/team', [TeamController::class, 'index'])
        ->middleware('http.cache:team')
        ->name('api.team.index');
    Route::get('/team/{slug}', [TeamController::class, 'show'])
        ->middleware('http.cache:team')
        ->name('api.team.show');

    Route::prefix('admin/team')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
        ->group(function (): void {
            Route::get('/', [TeamMemberAdminController::class, 'index'])
                ->middleware('permission:'.TeamPermission::VIEW->value)
                ->name('admin.team.index');
            Route::post('/', [TeamMemberAdminController::class, 'store'])
                ->middleware('permission:'.TeamPermission::CREATE->value)
                ->name('admin.team.store');
            Route::get('/{member}', [TeamMemberAdminController::class, 'show'])
                ->middleware('permission:'.TeamPermission::VIEW->value)
                ->name('admin.team.show');
            Route::patch('/{member}', [TeamMemberAdminController::class, 'update'])
                ->middleware('permission:'.TeamPermission::UPDATE->value)
                ->name('admin.team.update');
            Route::put('/{member}/translations/{locale}', [TeamMemberAdminController::class, 'writeTranslation'])
                ->middleware('permission:'.TeamPermission::UPDATE->value)
                ->name('admin.team.translations.write');
            Route::delete('/{member}', [TeamMemberAdminController::class, 'destroy'])
                ->middleware('permission:'.TeamPermission::DELETE->value)
                ->name('admin.team.destroy');
        });
});
