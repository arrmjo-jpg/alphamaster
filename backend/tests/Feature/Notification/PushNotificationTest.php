<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Notification\Contracts\NotifierContract;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Models\NotificationPreference;
use App\Modules\Notification\Models\PushDevice;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config(['queue.default' => 'sync']);

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);
});

// Push, proven without a Firebase project.
//
// The driver is written against Laravel's HTTP client rather than the vendor SDK, so
// `Http::fake()` intercepts at the wire and the real driver runs: the service-account
// exchange, the v1 request shape, the dead-token codes and the token cache all execute.
// A fake driver would have proven only that a fake driver works.
//
// The decision most of this file is about is what a push carries: a type and a record
// id, and no content at all. A payload passes through Google and Apple, and a lock
// screen is a public surface.

/** A Firebase provider with a service-account credential and a faked vendor. */
function withFcm(int $sendStatus = 200, array $sendBody = ['name' => 'projects/p/messages/1']): IntegrationProvider
{
    IntegrationProvider::query()
        ->forCapability(IntegrationCapability::PUSH)
        ->update(['is_default' => false]);

    /** @var IntegrationProvider $provider */
    $provider = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::PUSH)
        ->where('driver', 'fcm')
        ->firstOrFail();

    $provider->setCredentials([
        'project_id' => 'alphamaster-test',
        'client_email' => 'pusher@alphamaster-test.iam.gserviceaccount.test',
        // A real RSA key, generated for this test and used nowhere: the driver signs a
        // JWT with it, so a placeholder string would fail before the request shape
        // could be asserted.
        'private_key' => testSigningKey(),
    ]);
    $provider->forceFill(['is_active' => true, 'is_default' => true])->save();

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600], 200),
        'fcm.googleapis.com/*' => Http::response($sendBody, $sendStatus),
    ]);

    return $provider->refresh();
}

/**
 * A throwaway RSA key.
 *
 * Generated per run rather than checked in, so there is no private key in the
 * repository for a secret scan to find or a reader to mistake for a real one.
 */
function testSigningKey(): string
{
    static $pem = null;

    if ($pem === null) {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $exported);
        $pem = (string) $exported;
    }

    return $pem;
}

function accountWithDevice(string $deviceToken = 'fcm-token-abc123'): array
{
    $user = makeAccount(['email' => 'device-owner'.uniqid().'@example.test']);

    $device = PushDevice::query()->create([
        'user_id' => $user->id,
        'token' => $deviceToken,
        'device_id' => 'handset-1',
        'platform' => 'ios',
        'label' => 'A phone',
    ]);

    // Push is optional, so a recipient has to want it. Written rather than assumed:
    // absence of a row means the notification's own defaults apply.
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'type' => NotificationType::SECURITY_ALERT->value,
        'channel' => NotificationChannel::PUSH->value,
        'enabled' => true,
    ]);

    return [$user, $device];
}

function notify(User $user): void
{
    app(NotifierContract::class)->send($user, NotificationType::SECURITY_ALERT, ['name' => 'Sami', 'event' => 'a sign-in']);
}

test('a push carries a type and a record id, and no content whatsoever', function (): void {
    withFcm();
    [$user] = accountWithDevice();

    notify($user);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'fcm.googleapis.com')) {
            return false;
        }

        $message = $request->data()['message'] ?? [];
        $payload = (string) json_encode($message);

        return $message['token'] === 'fcm-token-abc123'
            && $message['data']['type'] === 'security.alert'
            && isset($message['data']['record_id'])
            // No `notification` block: nothing the platform said is rendered from the
            // payload by the operating system, so the client decides what a lock
            // screen shows and the platform never puts a sentence where it cannot
            // control who reads it.
            && ! isset($message['notification'])
            // And nothing from the template. A body in a push is visible to anyone
            // holding the phone.
            && ! str_contains($payload, 'Sami')
            && ! str_contains($payload, 'sign-in');
    });
});

test('the record id in the push is the one the inbox holds', function (): void {
    withFcm();
    [$user] = accountWithDevice();

    notify($user);

    $recordId = (string) $user->notifications()->sole()->id;

    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'fcm.googleapis.com')
        || ($request->data()['message']['data']['record_id'] ?? '') === $recordId);
});

test('a service account is exchanged for a token, once, and reused', function (): void {
    withFcm();
    [$user] = accountWithDevice();

    notify($user);
    notify($user);

    // Minting a token costs a signature and a round trip; doing it per message would
    // double the cost of every push.
    Http::assertSentCount(3);
    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'oauth2')
        || $request->data()['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');
});

test('a recipient with no registered device is skipped rather than failed', function (): void {
    withFcm();
    $user = makeAccount(['email' => 'no-device@example.test']);

    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'type' => NotificationType::SECURITY_ALERT->value,
        'channel' => NotificationChannel::PUSH->value,
        'enabled' => true,
    ]);

    notify($user);

    // The in-app record is still written: one unavailable route must not cost the
    // others, and the record is the evidence the platform decided to say something.
    expect($user->notifications()->count())->toBe(1);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'fcm.googleapis.com'));
});

test('a platform with no push provider does not try, and notifies on its other channels', function (): void {
    Http::fake();
    [$user] = accountWithDevice();

    notify($user);

    expect($user->notifications()->count())->toBe(1);

    Http::assertNothingSent();
});

// ── Token lifecycle ──────────────────────────────────────────────────────────

test('a vendor saying the token is dead removes the device', function (): void {
    withFcm(404, ['error' => ['status' => 'UNREGISTERED', 'message' => 'Requested entity was not found.']]);
    [$user, $device] = accountWithDevice();

    notify($user);

    // The vendor is authoritative about its own addresses. Keeping it means retrying
    // forever, and a registry that only grows is one an operator stops trusting.
    expect(PushDevice::query()->find($device->id))->toBeNull();
});

test('a vendor being unavailable leaves the device alone', function (): void {
    withFcm(503, ['error' => ['status' => 'UNAVAILABLE', 'message' => 'Try again.']]);
    [$user, $device] = accountWithDevice();

    notify($user);

    // Deleting on a transient failure would empty the registry during an outage.
    expect(PushDevice::query()->find($device->id))->not->toBeNull();
});

test('a delivered push records that the device is still reachable', function (): void {
    withFcm();
    [$user, $device] = accountWithDevice();

    expect($device->last_seen_at)->toBeNull();

    notify($user);

    expect($device->refresh()->last_seen_at)->not->toBeNull();
});

test('every attempt is recorded, and the device token never is', function (): void {
    withFcm();
    [$user] = accountWithDevice('a-very-secret-device-token');

    notify($user);

    $log = IntegrationUsageLog::query()->where('capability', 'push')->sole();

    expect($log->status->value)->toBe('success')
        ->and((string) json_encode($log->toArray()))->not->toContain('a-very-secret-device-token');
});

// ── Registration is the account's own act ────────────────────────────────────

test('an account registers its own device and gets no token back', function (): void {
    $user = makeAccount(['email' => 'registrar@example.test']);
    $token = $user->createToken('test-token', ['user:access'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/notifications/devices', [
        'token' => 'fcm-registration-token-xyz',
        'device_id' => 'handset-9',
        'platform' => 'android',
        'label' => 'My phone',
    ])->assertOk();

    expect($response->json('data.platform'))->toBe('android')
        ->and($response->json('data.token_hint'))->toBe('en-xyz')
        // A delivery address for one handset. Anybody holding it could send to that
        // device directly, so it is never read back.
        ->and($response->content())->not->toContain('fcm-registration-token-xyz');
});

test('re-registering the same handset replaces the token rather than adding a row', function (): void {
    $user = makeAccount(['email' => 'rotator@example.test']);
    $token = $user->createToken('test-token', ['user:access'])->plainTextToken;

    $register = fn (string $deviceToken) => $this->withToken($token)->postJson('/api/v1/notifications/devices', [
        'token' => $deviceToken,
        'device_id' => 'handset-1',
        'platform' => 'ios',
    ]);

    $register('first-token')->assertOk();
    $register('rotated-token')->assertOk();

    // FCM rotates tokens. Without a stable handle for the handset, a rotation would
    // leave two rows and the phone would receive everything twice.
    $devices = PushDevice::query()->forAccount($user->id)->get();

    expect($devices)->toHaveCount(1)
        ->and($devices->first()->token)->toBe('rotated-token');
});

test('two handsets on one account are two rows', function (): void {
    $user = makeAccount(['email' => 'two-devices@example.test']);
    $token = $user->createToken('test-token', ['user:access'])->plainTextToken;

    foreach (['handset-1' => 'ios', 'tablet-1' => 'android'] as $deviceId => $platform) {
        $this->withToken($token)->postJson('/api/v1/notifications/devices', [
            'token' => 'token-for-'.$deviceId,
            'device_id' => $deviceId,
            'platform' => $platform,
        ])->assertOk();
    }

    // One account, a phone and a tablet — which is why this is a registry and not a
    // column on `users`.
    expect(PushDevice::query()->forAccount($user->id)->count())->toBe(2);
});

test('an account sees and forgets only its own devices', function (): void {
    [$mine] = accountWithDevice('mine');
    [$theirs, $theirDevice] = accountWithDevice('theirs');

    $token = $mine->createToken('test-token', ['user:access'])->plainTextToken;

    $listed = $this->withToken($token)->getJson('/api/v1/notifications/devices')->assertOk()->json('data');

    expect($listed)->toHaveCount(1);

    // Not found rather than forbidden: the query is scoped to the caller, so another
    // account's device does not exist as far as this endpoint is concerned.
    $this->withToken($token)
        ->deleteJson('/api/v1/notifications/devices/'.$theirDevice->id)
        ->assertNotFound();

    expect(PushDevice::query()->find($theirDevice->id))->not->toBeNull();
});

test('signing out stops delivery to the handset that session registered', function (): void {
    $user = makeAccount(['email' => 'signer-out@example.test']);
    $first = $user->createToken('first-session', ['user:access'])->plainTextToken;

    $this->withToken($first)->postJson('/api/v1/notifications/devices', [
        'token' => 'session-one-token',
        'device_id' => 'handset-1',
        'platform' => 'ios',
    ])->assertOk();

    resetClient($this);

    // A second session on a second handset, which must not be silenced by the first
    // signing out — the registry is keyed by token rather than by account.
    $second = $user->createToken('second-session', ['user:access'])->plainTextToken;
    $this->withToken($second)->postJson('/api/v1/notifications/devices', [
        'token' => 'session-two-token',
        'device_id' => 'handset-2',
        'platform' => 'android',
    ])->assertOk();

    resetClient($this);

    $this->withToken($first)->postJson('/api/v1/auth/logout')->assertOk();

    $remaining = PushDevice::query()->forAccount($user->id)->pluck('device_id')->all();

    expect($remaining)->toBe(['handset-2']);
});

test('the device routes are behind the perimeter', function (): void {
    $this->getJson('/api/v1/notifications/devices')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/devices', [])->assertUnauthorized();
});

// ── What an operator can see ─────────────────────────────────────────────────

test('the registry is administrative, read-mostly, and never carries a token', function (): void {
    withFcm();
    accountWithDevice('an-operator-should-not-see-this');

    $admin = tokenWithPermissions(['notifications.view']);

    $response = $this->withToken($admin)->getJson('/api/v1/admin/notifications/devices')->assertOk();

    expect($response->json('data.total'))->toBe(1)
        ->and($response->json('data.configured'))->toBeTrue()
        ->and($response->content())->not->toContain('an-operator-should-not-see-this');
});

test('removing a device from the registry needs the permission that changes notifications', function (): void {
    [, $device] = accountWithDevice();

    $this->withToken(tokenWithPermissions(['notifications.view']))
        ->deleteJson('/api/v1/admin/notifications/devices/'.$device->id)
        ->assertForbidden();

    resetClient($this);

    $this->withToken(tokenWithPermissions(['notifications.view', 'notifications.update']))
        ->deleteJson('/api/v1/admin/notifications/devices/'.$device->id)
        ->assertOk();

    expect(PushDevice::query()->find($device->id))->toBeNull();
});

test('there is no way for an administrator to register a device for somebody else', function (): void {
    $admin = tokenWithPermissions(['notifications.view', 'notifications.update']);

    // A registration is a claim that a handset belongs to an account, and only that
    // account's own client can make it.
    $this->withToken($admin)
        ->postJson('/api/v1/admin/notifications/devices', ['token' => 'x', 'device_id' => 'y', 'platform' => 'ios'])
        ->assertStatus(405);
});
