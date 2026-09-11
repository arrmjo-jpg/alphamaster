<?php

declare(strict_types=1);

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Notification\Controllers\Admin\AnnouncementAdminController;
use App\Modules\Notification\Controllers\Admin\NotificationTemplateAdminController;
use App\Modules\Notification\Controllers\Admin\PushAdminController;
use App\Modules\Notification\Controllers\Api\NotificationInboxController;
use App\Modules\Notification\Controllers\Api\NotificationPreferenceController;
use App\Modules\Notification\Controllers\Api\PushDeviceController;
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

            // Where a push can reach the caller (ADR 0045). On the same terms as their
            // preferences and their inbox: their devices, about them, behind no
            // permission. Every query is scoped to the caller and none of these routes
            // takes an account identifier, so there is no way to register a device
            // against somebody else's account however they are called.
            //
            // Declared before the `{notification}` route above would ever be reached by
            // one of these paths, because `devices` is a literal segment on its own
            // prefix rather than a record id.
            Route::get('/notifications/devices', [PushDeviceController::class, 'index'])
                ->name('api.notifications.devices.index');

            Route::post('/notifications/devices', [PushDeviceController::class, 'store'])
                ->name('api.notifications.devices.store');

            Route::delete('/notifications/devices/{device}', [PushDeviceController::class, 'destroy'])
                ->name('api.notifications.devices.destroy');
        });

    // The device registry, administrative and read-mostly (ADR 0045). Behind
    // `notifications.view` because it is a view of who the platform's notifications
    // reach; removing a row needs `notifications.update`, because it changes who
    // receives them.
    //
    // There is no administrative *registration*: a registration is a claim that a
    // handset belongs to an account, and only that account's own client can make it.
    Route::prefix('admin/notifications')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
        ->group(function (): void {
            Route::get('/devices', [PushAdminController::class, 'index'])
                ->middleware('permission:'.AdminPermission::NOTIFICATIONS_VIEW->value)
                ->name('admin.notifications.devices.index');

            Route::delete('/devices/{device}', [PushAdminController::class, 'destroy'])
                ->middleware('permission:'.AdminPermission::NOTIFICATIONS_UPDATE->value)
                ->name('admin.notifications.devices.destroy');
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
