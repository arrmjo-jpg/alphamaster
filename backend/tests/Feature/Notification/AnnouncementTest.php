<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Notification\Models\NotificationRecord;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    // See NotificationInboxTest: the container exports QUEUE_CONNECTION=redis and a real
    // environment variable beats phpunit.xml, so delivery has to be made synchronous for
    // a test to see what was actually written.
    config(['queue.default' => 'sync']);

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);
});

// Announcements: the one notification an administrator raises by hand.
//
// `admin.announcement` has had a seeded, translated, active template since Phase 9 and
// nothing has ever raised one. What is under test is who it reaches, who may send it,
// and that it lands where the type says it should — the in-app record, not everybody's
// mailbox.

function audience(): array
{
    return [
        'administrator' => makeAccount([
            'name' => 'An Administrator',
            'email' => 'an-admin@example.test',
            'account_type' => AccountType::ADMIN,
        ]),
        'member' => makeAccount([
            'name' => 'A Member',
            'email' => 'a-member@example.test',
        ]),
        'suspended' => makeAccount([
            'name' => 'A Suspended Account',
            'email' => 'suspended@example.test',
            'is_active' => false,
        ]),
    ];
}

test('an announcement to everyone reaches every active account and skips the rest', function (): void {
    $people = audience();
    $token = tokenWithPermissions([AdminPermission::NOTIFICATIONS_SEND->value]);

    $this->withToken($token)->postJson('/api/v1/admin/notifications/announcements', [
        'subject' => 'Scheduled maintenance',
        'body' => 'The platform will be unavailable on Sunday.',
        'audience' => 'everyone',
    ])->assertOk()->assertJsonPath('data.audience', 'everyone');

    expect(NotificationRecord::query()->where('notifiable_id', $people['administrator']->id)->count())->toBe(1)
        ->and(NotificationRecord::query()->where('notifiable_id', $people['member']->id)->count())->toBe(1)
        // A message to an account that cannot sign in is a message nobody will read,
        // and the in-app record is where this notification lands.
        ->and(NotificationRecord::query()->where('notifiable_id', $people['suspended']->id)->count())->toBe(0);
});

test('an announcement to administrators reaches nobody else', function (): void {
    $people = audience();
    $token = tokenWithPermissions([AdminPermission::NOTIFICATIONS_SEND->value]);

    $this->withToken($token)->postJson('/api/v1/admin/notifications/announcements', [
        'subject' => 'Rotate your credentials',
        'body' => 'Please rotate the provider credentials this week.',
        'audience' => 'administrators',
    ])->assertOk();

    expect(NotificationRecord::query()->where('notifiable_id', $people['administrator']->id)->count())->toBe(1)
        ->and(NotificationRecord::query()->where('notifiable_id', $people['member']->id)->count())->toBe(0);
});

test('the message a recipient reads is the one that was written', function (): void {
    $member = makeAccount(['name' => 'A Member', 'email' => 'reader@example.test']);
    $token = tokenWithPermissions([AdminPermission::NOTIFICATIONS_SEND->value]);

    $this->withToken($token)->postJson('/api/v1/admin/notifications/announcements', [
        'subject' => 'A subject nobody else chose',
        'body' => 'A body nobody else wrote.',
        'audience' => 'everyone',
    ])->assertOk();

    $record = NotificationRecord::query()->where('notifiable_id', $member->id)->firstOrFail();

    /** @var array<string, mixed> $data */
    $data = $record->data;

    expect($data['subject'])->toBe('A subject nobody else chose')
        ->and($data['body'])->toBe('A body nobody else wrote.')
        ->and($data['type'])->toBe('admin.announcement');
});

test('sending needs notifications.send, and notifications.update is not enough', function (): void {
    audience();
    $token = tokenWithPermissions([AdminPermission::NOTIFICATIONS_UPDATE->value]);

    $this->withToken($token)->postJson('/api/v1/admin/notifications/announcements', [
        'subject' => 'Refused',
        'body' => 'This should not be delivered.',
        'audience' => 'everyone',
    ])->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');

    // Changing the wording every recipient reads and writing to everyone are different
    // powers, and the first is not a reason to hold the second.
    expect(NotificationRecord::query()->count())->toBe(0);
});

test('an audience the platform does not define is refused', function (): void {
    $token = tokenWithPermissions([AdminPermission::NOTIFICATIONS_SEND->value]);

    $this->withToken($token)->postJson('/api/v1/admin/notifications/announcements', [
        'subject' => 'Anything',
        'body' => 'Anything at all.',
        'audience' => 'everyone-in-marketing',
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('an announcement with no wording is refused rather than delivered empty', function (): void {
    $token = tokenWithPermissions([AdminPermission::NOTIFICATIONS_SEND->value]);

    $this->withToken($token)->postJson('/api/v1/admin/notifications/announcements', [
        'audience' => 'everyone',
    ])->assertStatus(422);
});

test('delivery is queued on the notifications queue rather than run in the request', function (): void {
    // The one test that must not make delivery synchronous: what it asserts is that a
    // burst of announcements cannot delay the request that raised them (ADR 0020).
    config(['queue.default' => 'redis']);
    Queue::fake();

    audience();
    $token = tokenWithPermissions([AdminPermission::NOTIFICATIONS_SEND->value]);

    $this->withToken($token)->postJson('/api/v1/admin/notifications/announcements', [
        'subject' => 'Queued',
        'body' => 'This is queued, not sent inline.',
        'audience' => 'everyone',
    ])->assertOk();

    // A queued notification is pushed as SendQueuedNotifications carrying the
    // notification, which is where the queue name lives — the same assertion
    // NotificationCenterTest makes about every other type.
    Queue::assertPushed(SendQueuedNotifications::class, function ($job): bool {
        return $job->notification->queue === 'notifications';
    });
});

test('the count returned is the recipients it was queued for', function (): void {
    $people = audience();
    $token = tokenWithPermissions([AdminPermission::NOTIFICATIONS_SEND->value]);

    $active = User::query()->where('is_active', true)->count();

    $this->withToken($token)->postJson('/api/v1/admin/notifications/announcements', [
        'subject' => 'Counted',
        'body' => 'Counted recipients.',
        'audience' => 'everyone',
    ])->assertOk()->assertJsonPath('data.recipients', $active);

    expect($people['suspended']->is_active)->toBeFalse();
});
