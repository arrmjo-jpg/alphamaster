<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Notification\Models\PushDevice;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Services\SettingService;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    // Notifications are ShouldQueue and the container exports a Redis queue (ADR 0027),
    // so without this the jobs would leave the process and the test would measure
    // nothing. Sync runs the same jobs, in order, inside the request.
    config(['queue.default' => 'sync']);

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    // Sign-in from a native client with the captcha off, as the local environment runs
    // it. Production keeps the captcha; ADR 0045 records that native sign-in needs a
    // mobile-capable one before it can ship there.
    app(SettingService::class)->set('auth', 'captcha_enabled', false);
});

// The whole path, through the API a mobile client actually calls.
//
//   an operator saves the Firebase service account through the Admin's endpoint
//   → an account signs in and gets a bearer token
//   → the handset registers its FCM token
//   → the account turns push on (it is off by default)
//   → an administrator raises an announcement
//   → the notification job runs the push channel
//   → the FCM driver exchanges the service account and sends
//   → the payload is a type and a record id, and nothing else
//   → the handset fetches the record with its own token and gets the words
//
// Firebase is faked at the wire, so the real driver runs: the JWT is signed with the key
// saved through the endpoint, the token exchange happens, and the v1 request is built.

function e2eSigningKey(): string
{
    static $pem = null;

    if ($pem === null) {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $exported);
        $pem = (string) $exported;
    }

    return $pem;
}

/**
 * @param  array<string, mixed>  $sendBody
 */
function e2eFirebase(int $sendStatus = 200, array $sendBody = ['name' => 'projects/alphamaster-test/messages/1']): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.end-to-end', 'expires_in' => 3600], 200),
        'fcm.googleapis.com/*' => Http::response($sendBody, $sendStatus),
    ]);
}

function e2eConfigureFirebase(mixed $test): void
{
    $provider = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::PUSH)
        ->where('driver', 'fcm')
        ->firstOrFail();

    $test->withToken(tokenWithPermissions(['integrations.view', 'integrations.update']))
        // The file as Firebase issues it, pasted whole — what the Admin sends.
        ->putJson('/api/v1/admin/integrations/providers/'.$provider->id, [
            'is_active' => true,
            'service_account_json' => (string) json_encode([
                'type' => 'service_account',
                'project_id' => 'alphamaster-test',
                'private_key_id' => str_repeat('e', 40),
                'private_key' => e2eSigningKey(),
                'client_email' => 'firebase-adminsdk@alphamaster-test.iam.gserviceaccount.com',
                'client_id' => '100000000000000000002',
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ])
        ->assertOk();

    resetClient($test);
}

/**
 * Sign in the way the mobile client does and return its bearer token.
 */
function e2eSignIn(mixed $test, User $user, string $password): string
{
    $token = $test->postJson('/api/v1/auth/login', [
        'identifier' => $user->email,
        'password' => $password,
    ])->assertOk()->json('data.token');

    expect($token)->toBeString()->not->toBe('');

    resetClient($test);

    return (string) $token;
}

function e2eRegisterHandset(mixed $test, string $bearer, string $fcmToken = 'handset-registration-token'): void
{
    $test->withToken($bearer)->postJson('/api/v1/notifications/devices', [
        'token' => $fcmToken,
        'device_id' => 'install-7f3a',
        'platform' => 'android',
        'label' => 'Pixel',
    ])->assertOk();

    resetClient($test);
}

function e2eEnablePush(mixed $test, string $bearer, bool $enabled = true): void
{
    $test->withToken($bearer)->putJson('/api/v1/notifications/preferences', [
        'preferences' => [['type' => 'admin.announcement', 'channel' => 'push', 'enabled' => $enabled]],
    ])->assertOk();

    resetClient($test);
}

function e2eAnnounce(mixed $test): void
{
    $test->withToken(tokenWithPermissions(['notifications.send']))
        ->postJson('/api/v1/admin/notifications/announcements', [
            'subject' => 'Maintenance tonight',
            'body' => 'The service pauses at 22:00.',
            'audience' => 'everyone',
        ])
        ->assertOk();

    resetClient($test);
}

/**
 * The FCM send requests the fake received.
 *
 * @return list<array<string, mixed>>
 */
function e2eSentMessages(): array
{
    return Http::recorded(fn ($request): bool => str_contains($request->url(), 'fcm.googleapis.com'))
        ->map(fn (array $pair): array => $pair[0]->data()['message'])
        ->values()
        ->all();
}

function e2eListener(): array
{
    $passphrase = 'correct-horse-battery-staple';

    return [makeAccount(['email' => 'listener@example.test', 'password' => $passphrase]), $passphrase];
}

test('a signed-in handset is told a type and an id, and fetches the words with its own token', function (): void {
    e2eFirebase();
    e2eConfigureFirebase($this);

    [$user, $password] = e2eListener();
    $bearer = e2eSignIn($this, $user, $password);

    e2eRegisterHandset($this, $bearer);
    expect(PushDevice::query()->forAccount($user->id)->count())->toBe(1);

    e2eEnablePush($this, $bearer);
    e2eAnnounce($this);

    // One handset registered, so one send — the administrator who raised it has none.
    $messages = e2eSentMessages();
    expect($messages)->toHaveCount(1);

    $message = $messages[0];

    expect($message['token'])->toBe('handset-registration-token')
        // Data only. The operating system renders nothing from the payload.
        ->and($message)->not->toHaveKey('notification')
        ->and(array_keys($message['data']))->toBe(['type', 'record_id'])
        // Urgency is the one delivery option set, so a dozing phone is woken for it.
        ->and($message['android'])->toBe(['priority' => 'HIGH'])
        ->and($message['data']['type'])->toBe('admin.announcement')
        ->and((string) json_encode($message))->not->toContain('Maintenance')
        ->and((string) json_encode($message))->not->toContain('22:00');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/projects/alphamaster-test/messages:send')
        && $request->hasHeader('Authorization', 'Bearer ya29.end-to-end'));

    // The service account saved through the Admin's endpoint is the one that signed the
    // exchange: an RS256 assertion that verifies against the key's public half.
    $exchange = Http::recorded(fn ($request): bool => str_contains($request->url(), 'oauth2.googleapis.com'))->first()[0];
    [$header, $claims, $signature] = explode('.', (string) $exchange['assertion']);
    $public = openssl_pkey_get_details(openssl_pkey_get_private(e2eSigningKey()))['key'];
    $decode = fn (string $value): string => (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

    expect(openssl_verify($header.'.'.$claims, $decode($signature), $public, OPENSSL_ALGO_SHA256))->toBe(1)
        ->and(json_decode($decode($claims), true)['iss'])->toBe('firebase-adminsdk@alphamaster-test.iam.gserviceaccount.com');

    // What the handset does next: fetch the record the push named, with its own token.
    $fetched = $this->withToken($bearer)
        ->getJson('/api/v1/notifications/'.$message['data']['record_id'])
        ->assertOk();

    expect($fetched->json('data.type'))->toBe('admin.announcement')
        ->and($fetched->json('data.subject'))->toBe('Maintenance tonight')
        ->and($fetched->json('data.body'))->toBe('The service pauses at 22:00.')
        ->and($fetched->json('data.read_at'))->toBeNull();

    // The attempt is on the record as a success, and the device as reachable.
    expect(IntegrationUsageLog::query()->where('capability', 'push')->where('status', 'success')->count())->toBe(1)
        ->and(PushDevice::query()->forAccount($user->id)->sole()->last_seen_at)->not->toBeNull();

    // Who was told and how many — never what. Everyone is every active account: the
    // listener and the two operators this test signed in to configure and announce.
    $audit = AuditRecord::query()->where('action', AuditAction::ANNOUNCEMENT_SENT)->sole();
    expect($audit->context)->toBe([
        'audience' => 'everyone',
        'recipients' => User::query()->where('is_active', true)->count(),
    ])
        ->and((string) json_encode($audit->toArray()))->not->toContain('Maintenance');
});

test('a vendor failure is recorded as a failure and never as delivered', function (): void {
    e2eFirebase(503, ['error' => ['status' => 'UNAVAILABLE', 'message' => 'The service is currently unavailable.']]);
    e2eConfigureFirebase($this);

    [$user, $password] = e2eListener();
    $bearer = e2eSignIn($this, $user, $password);

    e2eRegisterHandset($this, $bearer);
    e2eEnablePush($this, $bearer);

    $registeredAt = PushDevice::query()->forAccount($user->id)->sole()->last_seen_at;

    $this->travel(10)->minutes();

    e2eAnnounce($this);

    expect(e2eSentMessages())->toHaveCount(1);

    $logs = IntegrationUsageLog::query()->where('capability', 'push')->get();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->status->value)->toBe('failure')
        ->and($logs->first()->error_code)->toBe('UNAVAILABLE');

    // Unavailable is not dead: the device stays, and nothing claims it was reached.
    $device = PushDevice::query()->forAccount($user->id)->sole();
    expect($device->last_seen_at?->toIso8601String())->toBe($registeredAt?->toIso8601String());

    // The in-app record is written regardless, so the message is not lost.
    expect($this->withToken($bearer)->getJson('/api/v1/notifications')->json('meta.pagination.total'))->toBe(1);
});

test('a vendor saying the token is dead removes the handset instead of retrying it', function (): void {
    e2eFirebase(404, ['error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.']]);
    e2eConfigureFirebase($this);

    [$user, $password] = e2eListener();
    $bearer = e2eSignIn($this, $user, $password);

    e2eRegisterHandset($this, $bearer);
    e2eEnablePush($this, $bearer);
    e2eAnnounce($this);

    expect(PushDevice::query()->forAccount($user->id)->count())->toBe(0);
});

test('push stays off until the recipient turns it on', function (): void {
    e2eFirebase();
    e2eConfigureFirebase($this);

    [$user, $password] = e2eListener();
    $bearer = e2eSignIn($this, $user, $password);

    e2eRegisterHandset($this, $bearer);
    e2eAnnounce($this);

    // A registered handset is an address, not consent. The default for an
    // announcement is the in-app record alone.
    expect(e2eSentMessages())->toBe([]);

    e2eEnablePush($this, $bearer);
    e2eEnablePush($this, $bearer, false);
    e2eAnnounce($this);

    expect(e2eSentMessages())->toBe([]);
});

test('signing out takes the handset off the air', function (): void {
    e2eFirebase();
    e2eConfigureFirebase($this);

    [$user, $password] = e2eListener();
    $bearer = e2eSignIn($this, $user, $password);

    e2eRegisterHandset($this, $bearer);
    e2eEnablePush($this, $bearer);

    $this->withToken($bearer)->postJson('/api/v1/auth/logout')->assertOk();
    resetClient($this);

    e2eAnnounce($this);

    // A shared phone must not keep receiving the previous account's notifications.
    expect(e2eSentMessages())->toBe([])
        ->and(PushDevice::query()->forAccount($user->id)->count())->toBe(0);
});
