<?php

declare(strict_types=1);

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Core\Controllers\Admin\AuditAdminController;
use App\Modules\Core\Controllers\Admin\ConfigurationBackupController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'healthy',
                'timestamp' => now()->toIso8601String(),
                'framework' => 'Laravel '.app()->version(),
            ],
        ]);
    })->name('api.health');

    // The administrative trail (ADR 0037).
    //
    // Reading and archiving are separate powers on separate permissions. There is no
    // write endpoint and there will not be one: the trail is append-only, and the
    // accounts with the most reason to alter it are exactly the ones with
    // administrative access.
    Route::prefix('admin/audit')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin'])
        ->group(function (): void {
            Route::get('/', [AuditAdminController::class, 'index'])
                ->middleware('permission:'.AdminPermission::AUDIT_VIEW->value)
                ->name('admin.audit.index');

            // Triggered by a person, never scheduled. A silent periodic cleanup is
            // indistinguishable, from outside, from evidence disappearing.
            Route::post('/archive', [AuditAdminController::class, 'archive'])
                ->middleware('permission:'.AdminPermission::AUDIT_MANAGE->value)
                ->name('admin.audit.archive');
        });

    // Moving configuration in and out of this deployment (ADR 0039).
    //
    // One permission for both directions, and deliberately not settings.update: an
    // export reads every non-secret value and names every secret, and a restore
    // rewrites configuration wholesale.
    Route::prefix('admin/configuration')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin',
            'permission:'.AdminPermission::SETTINGS_BACKUP_MANAGE->value])
        ->group(function (): void {
            Route::post('/export', [ConfigurationBackupController::class, 'export'])
                ->name('admin.configuration.export');

            Route::post('/restore', [ConfigurationBackupController::class, 'restore'])
                ->name('admin.configuration.restore');
        });
});
