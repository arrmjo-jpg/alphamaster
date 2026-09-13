<?php

declare(strict_types=1);

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Notification\Controllers\Admin\AnnouncementAdminController;
use App\Modules\Notification\Controllers\Admin\NotificationTemplateAdminController;
use App\Modules\Notification\Controllers\Api\NotificationInboxController;
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

            // The caller's own in-app records, on the same terms as their
            // preferences: their messages, about them, behind no permission of their
            // own. Every query is scoped to the caller, which is the whole of the
            // authorization — there is no endpoint that reads another account's.
            Route::get('/notifications', [NotificationInboxController::class, 'index'])
                ->name('api.notifications.index');

            Route::post('/notifications/read-all', [NotificationInboxController::class, 'readAll'])
                ->name('api.notifications.read-all');

            // Declared after `read-all` so the literal segment is matched first and a
            // record can never be addressed by that name.
            Route::post('/notifications/{notification}/read', [NotificationInboxController::class, 'read'])
                ->name('api.notifications.read');
        });

    // Template wording is administrative, behind the full five-stage stack.
    Route::prefix('admin/notifications')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
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

            // Sending is its own permission. Correcting the wording of a template and
            // writing to every account are different powers with different blast
            // radii, and the first is not a reason to hold the second.
            Route::post('/announcements', [AnnouncementAdminController::class, 'store'])
                ->middleware('permission:'.AdminPermission::NOTIFICATIONS_SEND->value)
                ->name('admin.notifications.announcements.store');
        });
});
