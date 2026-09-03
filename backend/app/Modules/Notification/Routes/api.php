<?php

declare(strict_types=1);

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Notification\Controllers\Admin\NotificationTemplateAdminController;
use App\Modules\Notification\Controllers\Api\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // A recipient's own preferences. Not administrative: these are the caller's
    // settings about their own messages, so any fully signed-in account may manage
    // them and an enrolment token may not.
    // Abilities are named literally rather than through the Auth enum: every other
    // module writes its middleware string the same way, and importing Auth here would
    // be a dependency the architecture rules cannot see, since they analyse classes
    // and a route file is not one.
    Route::middleware(['auth:sanctum', 'ability:admin:access,user:access', 'active'])
        ->group(function (): void {
            Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'index'])
                ->name('api.notifications.preferences.index');
            Route::put('/notifications/preferences', [NotificationPreferenceController::class, 'update'])
                ->name('api.notifications.preferences.update');
        });

    // Template wording is administrative, behind the full five-stage stack.
    Route::prefix('admin/notifications')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin'])
        ->group(function (): void {
            Route::get('/templates', [NotificationTemplateAdminController::class, 'index'])
                ->middleware('permission:'.AdminPermission::NOTIFICATIONS_VIEW->value)
                ->name('admin.notifications.templates.index');

            Route::get('/templates/{template}', [NotificationTemplateAdminController::class, 'show'])
                ->middleware('permission:'.AdminPermission::NOTIFICATIONS_VIEW->value)
                ->name('admin.notifications.templates.show');

            Route::put('/templates/{template}', [NotificationTemplateAdminController::class, 'update'])
                ->middleware('permission:'.AdminPermission::NOTIFICATIONS_UPDATE->value)
                ->name('admin.notifications.templates.update');
        });
});
