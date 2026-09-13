<?php

declare(strict_types=1);

use App\Modules\Core\Controllers\Admin\AuditAdminController;
use App\Modules\Core\Controllers\Admin\ConfigurationBackupController;
use Illuminate\Support\Facades\Route;

/*
 * Permissions are named literally here, and only here.
 *
 * Every other module writes `AdminPermission::X->value` in its route file, and may:
 * the class rules forbid Core from depending on Authorization and permit the rest.
 * Importing the enum here would be exactly the dependency ADR 0029 item 1 describes —
 * one the architecture rules cannot see, because they analyse classes and a route
 * file is not one.
 *
 * The strings are checked against the catalogue by ArchitectureRouteFileTest, so a
 * literal that stops naming a real permission fails a test rather than quietly
 * leaving an endpoint unreachable.
 */
Route::prefix('v1')->group(function (): void {
    /**
     * Liveness probe.
     *
     * Answers from the application itself, so it reports that PHP is executing rather
     * than that any dependency is reachable. The summary is here because the generated
     * contract requires one per operation and a closure carries no docblock of its own
     * to infer from — every other operation gets its summary from its controller method.
     */
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
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
        ->group(function (): void {
            Route::get('/', [AuditAdminController::class, 'index'])
                ->middleware('permission:audit.view')
                ->name('admin.audit.index');

            // Triggered by a person, never scheduled. A silent periodic cleanup is
            // indistinguishable, from outside, from evidence disappearing.
            Route::post('/archive', [AuditAdminController::class, 'archive'])
                ->middleware('permission:audit.manage')
                ->name('admin.audit.archive');
        });

    // Moving configuration in and out of this deployment (ADR 0039).
    //
    // One permission for both directions, and deliberately not settings.update: an
    // export reads every non-secret value and names every secret, and a restore
    // rewrites configuration wholesale.
    Route::prefix('admin/configuration')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified',
            'permission:settings.backup.manage'])
        ->group(function (): void {
            Route::post('/export', [ConfigurationBackupController::class, 'export'])
                ->name('admin.configuration.export');

            Route::post('/restore', [ConfigurationBackupController::class, 'restore'])
                ->name('admin.configuration.restore');
        });
});
