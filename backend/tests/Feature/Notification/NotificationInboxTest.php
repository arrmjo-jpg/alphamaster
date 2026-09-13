<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Notification\Contracts\NotifierContract;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Models\NotificationRecord;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    // Notifications are ShouldQueue. The container exports QUEUE_CONNECTION=redis and a
    // real environment variable beats phpunit.xml (ADR 0027), so without this the jobs
    // are pushed to Redis and never run inside the test — and an inbox test that reads
    // no rows would pass by measuring nothing.
    config(['queue.default' => 'sync']);

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);
});

// Reading what the platform decided to tell you.
//
// The in-app record is the one channel a recipient cannot silence, because it is the
// evidence a notification was raised. Until this endpoint existed the platform wrote
// that evidence and published nothing that could read it. What is under test is mostly
// the boundary: an inbox is one account's, and nothing here reaches another's.

function signedIn(array $attributes = []): array
{
    $user = makeAccount(array_merge([
        'email' => 'recipient'.uniqid().'@example.test',
    ], $attributes));

    return [$user, $user->createToken('test-token', ['user:access'])->plainTextToken];
}

function raise(User $recipient, NotificationType $type = NotificationType::ADMIN_ANNOUNCEMENT): void
{
    app(NotifierContract::class)->send($recipient, $type, [
        'subject' => 'A subject',
        'body' => 'A body',
        'event' => 'something happened',
        'name' => $recipient->name,
    ]);
}

test('the inbox carries the rendered message, not a re-render of the template', function (): void {
    [$user, $token] = signedIn();
    raise($user);

    $response = $this->withToken($token)->getJson('/api/v1/notifications')->assertOk();

    expect($response->json('data.0.type'))->toBe('admin.announcement')
        ->and($response->json('data.0.subject'))->toBe('A subject')
        ->and($response->json('data.0.body'))->toBe('A body')
        // The label sits beside the value and never replaces it (ADR 0030/0031).
        ->and($response->json('data.0.type_label'))->not->toBe('')
        ->and($response->json('data.0.read_at'))->toBeNull();
});

test('an inbox is one account and nothing reaches another', function (): void {
    [$mine, $token] = signedIn();
    [$theirs] = signedIn();

    raise($mine);
    raise($theirs);

    $response = $this->withToken($token)->getJson('/api/v1/notifications')->assertOk();

    expect($response->json('meta.pagination.total'))->toBe(1);

    // Addressing somebody else's record by its identifier is a 404 rather than a
    // refusal: confirming that a record exists is already more than a stranger
    // should learn.
    $other = NotificationRecord::query()->where('notifiable_id', $theirs->id)->firstOrFail();

    $this->withToken($token)->postJson('/api/v1/notifications/'.$other->id.'/read')
        ->assertNotFound();

    expect($other->fresh()->read_at)->toBeNull();
});

test('the unread count describes the inbox rather than the page', function (): void {
    [$user, $token] = signedIn();

    foreach (range(1, 3) as $ignored) {
        raise($user);
    }

    $response = $this->withToken($token)->getJson('/api/v1/notifications')->assertOk();
    expect($response->json('meta.unread'))->toBe(3);

    $first = NotificationRecord::query()->where('notifiable_id', $user->id)->firstOrFail();
    $this->withToken($token)->postJson('/api/v1/notifications/'.$first->id.'/read')->assertOk();

    expect($this->withToken($token)->getJson('/api/v1/notifications')->json('meta.unread'))->toBe(2);
});

test('the unread filter returns only what has not been read', function (): void {
    [$user, $token] = signedIn();
    raise($user);
    raise($user);

    $first = NotificationRecord::query()->where('notifiable_id', $user->id)->firstOrFail();
    $this->withToken($token)->postJson('/api/v1/notifications/'.$first->id.'/read')->assertOk();

    $response = $this->withToken($token)->getJson('/api/v1/notifications?unread=1')->assertOk();

    expect($response->json('meta.pagination.total'))->toBe(1)
        ->and($response->json('data.0.id'))->not->toBe($first->id);
});

test('marking read twice keeps the moment it was first read', function (): void {
    [$user, $token] = signedIn();
    raise($user);

    $record = NotificationRecord::query()->where('notifiable_id', $user->id)->firstOrFail();

    $first = $this->withToken($token)->postJson('/api/v1/notifications/'.$record->id.'/read')
        ->assertOk()->json('data.read_at');

    $this->travel(5)->minutes();

    $second = $this->withToken($token)->postJson('/api/v1/notifications/'.$record->id.'/read')
        ->assertOk()->json('data.read_at');

    // When somebody first saw a message is the fact worth keeping.
    expect($second)->toBe($first);
});

test('read-all marks everything and says how many that was', function (): void {
    [$user, $token] = signedIn();
    raise($user);
    raise($user);

    $this->withToken($token)->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.marked', 2);

    expect(NotificationRecord::query()->where('notifiable_id', $user->id)->whereNull('read_at')->count())
        ->toBe(0);

    // Idempotent, and honest about it: a second call marked nothing.
    $this->withToken($token)->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.marked', 0);
});

test('a record cannot be deleted, because it is the evidence it was sent', function (): void {
    [$user, $token] = signedIn();
    raise($user);

    $record = NotificationRecord::query()->where('notifiable_id', $user->id)->firstOrFail();

    // No route at all, deliberately — which is why this is a 404 and not a 405. A
    // recipient who could remove a record could remove the evidence that they were
    // told, the same reason the channel cannot be silenced (ADR 0019).
    $this->withToken($token)->deleteJson('/api/v1/notifications/'.$record->id)
        ->assertNotFound();

    expect($record->fresh())->not->toBeNull();
});

test('an unauthenticated caller reads no inbox at all', function (): void {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
});

test('the message is rendered in the recipient language, not the sender request', function (): void {
    [$user, $token] = signedIn(['preferred_locale' => 'ar']);

    app(NotifierContract::class)->send($user, NotificationType::SECURITY_ALERT, [
        'name' => $user->name,
        'event' => 'a test event',
    ]);

    $response = $this->withToken($token)->getJson('/api/v1/notifications')->assertOk();

    // A notification is read by its recipient rather than by whoever caused it, so the
    // stored copy is in their language and stays that way afterwards.
    expect($response->json('data.0.locale'))->toBe('ar')
        ->and($response->json('data.0.subject'))->not->toContain('Security alert');
});
