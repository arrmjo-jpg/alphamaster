<?php

declare(strict_types=1);

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Media\Controllers\Admin\MediaAdminController;
use App\Modules\Media\Controllers\Admin\MediaAnalysisAdminController;
use App\Modules\Media\Controllers\Api\AvatarController;
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

            // The bytes, as opposed to the record. Behind the same gate, because the
            // access decision and the file it protects must not come apart.
            Route::get('/media/{media}/file', [MediaController::class, 'file'])
                ->name('api.media.file');

            // The caller's own profile picture (ADR 0051 §4). No identifier: it is always
            // the signed-in account's.
            Route::post('/profile/avatar', [AvatarController::class, 'store'])
                ->name('api.profile.avatar.store');
            Route::delete('/profile/avatar', [AvatarController::class, 'destroy'])
                ->name('api.profile.avatar.destroy');
        });

    // Moderation is administrative, behind the full five-stage stack.
    Route::prefix('admin/media')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
        ->group(function (): void {
            Route::get('/', [MediaAdminController::class, 'index'])
                ->middleware('permission:'.AdminPermission::MEDIA_VIEW->value)
                ->name('admin.media.index');

            // Media analysis (ADR 0054). Declared before `/{media}`, which would otherwise
            // take `analysis` and `analyses` as a media id. The Admin is one consumer of the
            // capability; modules call Core's contract directly and need none of these.
            Route::get('/analysis', [MediaAnalysisAdminController::class, 'status'])
                ->middleware('permission:'.AdminPermission::MEDIA_ANALYSIS_VIEW->value)
                ->name('admin.media.analysis.status');

            Route::get('/analyses/{analysis}', [MediaAnalysisAdminController::class, 'show'])
                ->middleware('permission:'.AdminPermission::MEDIA_ANALYSIS_VIEW->value)
                ->name('admin.media.analyses.show');

            Route::post('/analyses/{analysis}/cancel', [MediaAnalysisAdminController::class, 'cancel'])
                ->middleware('permission:'.AdminPermission::MEDIA_ANALYSIS_REQUEST->value)
                ->name('admin.media.analyses.cancel');

            Route::post('/analyses/{analysis}/reviews', [MediaAnalysisAdminController::class, 'review'])
                ->middleware('permission:'.AdminPermission::MEDIA_ANALYSIS_REVIEW->value)
                ->name('admin.media.analyses.review');

            Route::get('/{media}/analyses', [MediaAnalysisAdminController::class, 'index'])
                ->middleware('permission:'.AdminPermission::MEDIA_ANALYSIS_VIEW->value)
                ->name('admin.media.analyses.index');

            Route::post('/{media}/analyses', [MediaAnalysisAdminController::class, 'store'])
                ->middleware('permission:'.AdminPermission::MEDIA_ANALYSIS_REQUEST->value)
                ->name('admin.media.analyses.store');

            Route::get('/{media}', [MediaAdminController::class, 'show'])
                ->middleware('permission:'.AdminPermission::MEDIA_VIEW->value)
                ->name('admin.media.show');

            Route::delete('/{media}', [MediaAdminController::class, 'destroy'])
                ->middleware('permission:'.AdminPermission::MEDIA_DELETE->value)
                ->name('admin.media.destroy');
        });
});
