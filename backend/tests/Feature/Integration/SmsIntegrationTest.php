<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Contracts\SmsDispatcherContract;
use App\Modules\Integration\Data\SmsMessage;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Exceptions\CredentialDecryptionException;
use App\Modules\Integration\Exceptions\NoProviderConfiguredException;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Integration\Services\SmsManager;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    $this->dispatcher = app(SmsDispatcherContract::class);
    $this->manager = app(SmsManager::class);
});

/**
 * Configure Twilio with working credentials and make it the default.
 */
function activateTwilio(array $settings = ['from' => '+15550000000']): IntegrationProvider
{
    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $twilio->setCredentials(['account_sid' => 'AC_test_sid', 'auth_token' => 'test_token']);
    $twilio->forceFill(['settings' => $settings, 'is_active' => true])->save();

    return $twilio->refresh();
}

function makeTwilioDefault(): void
{
    IntegrationProvider::query()->where('driver', 'log')->update(['is_default' => false]);
    IntegrationProvider::query()->where('driver', 'twilio')->update(['is_default' => true]);
}

// ── Provider configuration ────────────────────────────────────────────────────

test('a fresh installation can send without any operator configuration', function (): void {
    $result = $this->dispatcher->send(new SmsMessage('+15551234567', 'hello'));

    expect($result->successful)->toBeTrue()
        ->and($result->driver)->toBe('log');
});

test('the seeder provisions twilio inactive and without credentials', function (): void {
    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();

    expect($twilio->is_active)->toBeFalse()
        ->and($twilio->is_default)->toBeFalse()
        ->and($twilio->hasCredentials())->toBeFalse();
});

test('re-running the seeder never overwrites operator configuration or credentials', function (): void {
    $twilio = activateTwilio(['from' => '+15559999999']);
    $ciphertext = DB::table('integration_providers')->where('id', $twilio->id)->value('credentials');

    $this->seed(IntegrationProviderSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    $after = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();

    expect(DB::table('integration_providers')->where('id', $twilio->id)->value('credentials'))->toBe($ciphertext)
        ->and($after->is_active)->toBeTrue()
        ->and($after->settings['from'])->toBe('+15559999999')
        ->and(IntegrationProvider::query()->count())->toBe(2);
});

// ── Credentials at rest ───────────────────────────────────────────────────────

test('credentials are encrypted at rest and never readable from the column', function (): void {
    $twilio = activateTwilio();
    $stored = DB::table('integration_providers')->where('id', $twilio->id)->value('credentials');

    expect($stored)->toBeString()
        ->and($stored)->not->toContain('AC_test_sid')
        ->and($stored)->not->toContain('test_token')
        ->and($twilio->getCredentials())->toBe([
            'account_sid' => 'AC_test_sid',
            'auth_token' => 'test_token',
        ]);
});

test('credentials are never serialised by the model', function (): void {
    $twilio = activateTwilio();

    expect($twilio->toArray())->not->toHaveKey('credentials')
        ->and(json_encode($twilio))->not->toContain('AC_test_sid');
});

test('unreadable credentials raise instead of being handed to the vendor', function (): void {
    $twilio = activateTwilio();

    DB::table('integration_providers')->where('id', $twilio->id)
        ->update(['credentials' => 'not-a-valid-ciphertext']);

    expect(fn () => $twilio->refresh()->getCredentials())
        ->toThrow(CredentialDecryptionException::class);
});

// ── Driver resolution ─────────────────────────────────────────────────────────

test('the default driver is read from the database, not from config', function (): void {
    expect($this->manager->getDefaultDriver())->toBe('log');

    activateTwilio();
    makeTwilioDefault();

    expect(app(SmsManager::class)->getDefaultDriver())->toBe('twilio');
});

test('an inactive provider is never selected', function (): void {
    // Twilio is seeded inactive; it must not appear in the chain at all.
    expect($this->manager->providerChain()->pluck('driver')->all())->toBe(['log']);

    activateTwilio();

    expect(app(SmsManager::class)->providerChain()->pluck('driver')->all())
        ->toContain('twilio');
});

test('the chain puts the default first, then orders by priority', function (): void {
    activateTwilio();
    makeTwilioDefault();

    expect(app(SmsManager::class)->providerChain()->pluck('driver')->all())
        ->toBe(['twilio', 'log']);
});

test('sending with no active provider raises rather than failing silently', function (): void {
    IntegrationProvider::query()->update(['is_active' => false]);

    expect(fn () => app(SmsDispatcherContract::class)->send(new SmsMessage('+1555', 'x')))
        ->toThrow(NoProviderConfiguredException::class);
});

// ── The Twilio driver ─────────────────────────────────────────────────────────

test('the twilio driver sends the request shape twilio actually expects', function (): void {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM_reference_123'], 201)]);

    activateTwilio(['from' => '+15550000000']);
    makeTwilioDefault();

    $result = app(SmsDispatcherContract::class)->send(new SmsMessage('+15551234567', 'hello there'));

    expect($result->successful)->toBeTrue()
        ->and($result->driver)->toBe('twilio')
        ->and($result->reference)->toBe('SM_reference_123');

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/Accounts/AC_test_sid/Messages.json')
            && $request['To'] === '+15551234567'
            && $request['From'] === '+15550000000'
            && $request['Body'] === 'hello there'
            && $request->hasHeader('Authorization');
    });
});

test('a twilio rejection is reported as a failure, not an exception', function (): void {
    Http::fake(['api.twilio.com/*' => Http::response(['code' => 21211, 'message' => 'Invalid To number'], 400)]);

    activateTwilio();
    // Only twilio active, so there is nothing to fall back to.
    IntegrationProvider::query()->where('driver', 'log')->update(['is_active' => false, 'is_default' => false]);
    IntegrationProvider::query()->where('driver', 'twilio')->update(['is_default' => true]);

    $result = app(SmsDispatcherContract::class)->send(new SmsMessage('bad', 'hello'));

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('21211')
        ->and($result->errorMessage)->toBe('Invalid To number');
});

test('a misconfigured twilio provider fails without attempting a request', function (): void {
    Http::fake();

    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $twilio->forceFill(['is_active' => true, 'settings' => ['from' => '']])->save();
    IntegrationProvider::query()->where('driver', 'log')->update(['is_active' => false, 'is_default' => false]);
    IntegrationProvider::query()->where('driver', 'twilio')->update(['is_default' => true]);

    $result = app(SmsDispatcherContract::class)->send(new SmsMessage('+15551234567', 'hello'));

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('MISCONFIGURED');

    Http::assertNothingSent();
});

// ── Failover ──────────────────────────────────────────────────────────────────

test('a failing primary falls back to the next provider', function (): void {
    Http::fake(['api.twilio.com/*' => Http::response(['code' => 30001, 'message' => 'Queue overflow'], 500)]);

    activateTwilio();
    makeTwilioDefault();

    $result = app(SmsDispatcherContract::class)->send(new SmsMessage('+15551234567', 'hello'));

    // Twilio was tried first and failed; log picked it up.
    expect($result->successful)->toBeTrue()
        ->and($result->driver)->toBe('log');

    $attempts = IntegrationUsageLog::query()->orderBy('created_at')->get();

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->driver)->toBe('twilio')
        ->and($attempts[0]->status)->toBe(UsageStatus::FAILURE)
        ->and($attempts[1]->driver)->toBe('log')
        ->and($attempts[1]->status)->toBe(UsageStatus::SUCCESS);
});

test('when every provider fails the last failure is returned', function (): void {
    Http::fake(['api.twilio.com/*' => Http::response(['code' => 500, 'message' => 'down'], 500)]);

    activateTwilio();
    makeTwilioDefault();
    // Break the log driver too by deactivating it, leaving only the failing vendor.
    IntegrationProvider::query()->where('driver', 'log')->update(['is_active' => false]);

    $result = app(SmsDispatcherContract::class)->send(new SmsMessage('+15551234567', 'hello'));

    expect($result->successful)->toBeFalse()
        ->and($result->driver)->toBe('twilio');
});

test('unreadable credentials do not stop the chain', function (): void {
    $twilio = activateTwilio();
    makeTwilioDefault();
    DB::table('integration_providers')->where('id', $twilio->id)->update(['credentials' => 'corrupt']);

    $result = app(SmsDispatcherContract::class)->send(new SmsMessage('+15551234567', 'hello'));

    expect($result->successful)->toBeTrue()
        ->and($result->driver)->toBe('log');

    $failed = IntegrationUsageLog::query()->where('driver', 'twilio')->firstOrFail();
    expect($failed->error_code)->toBe('CREDENTIALS_UNREADABLE');
});

// ── Usage logging ─────────────────────────────────────────────────────────────

test('every attempt is recorded with timing and never with message content', function (): void {
    $this->dispatcher->send(new SmsMessage('+15551234567', 'secret message body'));

    $log = IntegrationUsageLog::query()->firstOrFail();

    expect($log->capability)->toBe(IntegrationCapability::SMS)
        ->and($log->driver)->toBe('log')
        ->and($log->status)->toBe(UsageStatus::SUCCESS)
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0)
        // A usage log is for operating the integration, not for storing its content.
        ->and(json_encode($log->toArray()))->not->toContain('secret message body')
        ->and(json_encode($log->toArray()))->not->toContain('+15551234567');
});

test('usage history survives the removal of the provider it referred to', function (): void {
    activateTwilio();
    makeTwilioDefault();
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM_x'], 201)]);

    app(SmsDispatcherContract::class)->send(new SmsMessage('+15551234567', 'hello'));

    IntegrationProvider::query()->where('driver', 'twilio')->delete();

    $log = IntegrationUsageLog::query()->where('driver', 'twilio')->firstOrFail();

    expect($log->integration_provider_id)->toBeNull()
        ->and($log->driver)->toBe('twilio')
        ->and($log->capability)->toBe(IntegrationCapability::SMS);
});

// ── Database invariants ───────────────────────────────────────────────────────

test('the database rejects an unknown capability', function (): void {
    $rejected = false;
    try {
        DB::transaction(fn () => DB::table('integration_providers')->insert([
            'id' => (string) Str::ulid(), 'capability' => 'telepathy', 'driver' => 'x',
            'label' => 'x', 'is_active' => true, 'is_default' => false, 'priority' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    } catch (QueryException) {
        $rejected = true;
    }

    expect($rejected)->toBeTrue();
});

test('the database rejects an unknown usage status', function (): void {
    $rejected = false;
    try {
        DB::transaction(fn () => DB::table('integration_usage_logs')->insert([
            'id' => (string) Str::ulid(), 'capability' => 'sms', 'driver' => 'log',
            'status' => 'maybe', 'created_at' => now(), 'updated_at' => now(),
        ]));
    } catch (QueryException) {
        $rejected = true;
    }

    expect($rejected)->toBeTrue();
});

test('a capability can have at most one default provider', function (): void {
    $rejected = false;
    try {
        DB::transaction(fn () => DB::table('integration_providers')
            ->where('driver', 'twilio')
            ->update(['is_default' => true]));
    } catch (QueryException) {
        $rejected = true;
    }

    expect($rejected)->toBeTrue()
        ->and(IntegrationProvider::query()->where('is_default', true)->count())->toBe(1);
});

// ── Admin API ─────────────────────────────────────────────────────────────────

test('the provider listing reports credentials only as present or absent', function (): void {
    activateTwilio();
    $admin = adminWithRoles($this, ['super_admin'], 'integration-admin@example.com');

    $data = $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/integrations/providers')
        ->assertOk()
        ->json('data');

    $twilio = collect($data)->firstWhere('driver', 'twilio');

    expect($twilio['has_credentials'])->toBeTrue()
        ->and($twilio)->not->toHaveKey('credentials');

    // The secret must not appear anywhere in the payload.
    expect(json_encode($data))->not->toContain('AC_test_sid')
        ->and(json_encode($data))->not->toContain('test_token');
});

test('an admin without integrations.view cannot read providers', function (): void {
    $admin = adminWithRoles($this, ['support'], 'weak-integration@example.com');

    $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/integrations/providers')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

test('an admin without integrations.update cannot change a provider', function (): void {
    $provider = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $admin = adminWithRoles($this, ['editor'], 'noupdate@example.com');

    $this->withToken($admin['token'])
        ->putJson('/api/v1/admin/integrations/providers/'.$provider->id, ['label' => 'Hijacked'])
        ->assertStatus(403);

    expect($provider->refresh()->label)->toBe('Twilio');
});

test('a regular user cannot reach the integration admin API', function (): void {
    $regular = regularWithToken($this, 'integration-user@example.com');

    $this->withToken($regular['token'])
        ->getJson('/api/v1/admin/integrations/providers')
        ->assertStatus(403);
});

test('an admin can supply credentials, and omitting them leaves the secret intact', function (): void {
    $provider = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $admin = adminWithRoles($this, ['super_admin'], 'credentialer@example.com');

    $this->withToken($admin['token'])
        ->putJson('/api/v1/admin/integrations/providers/'.$provider->id, [
            'credentials' => ['account_sid' => 'AC_new', 'auth_token' => 'tok_new'],
            'settings' => ['from' => '+15557654321'],
            'is_active' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.has_credentials', true);

    $ciphertext = DB::table('integration_providers')->where('id', $provider->id)->value('credentials');

    expect($provider->refresh()->getCredentials()['account_sid'])->toBe('AC_new');

    // A later update that omits credentials must not disturb them.
    $this->withToken($admin['token'])
        ->putJson('/api/v1/admin/integrations/providers/'.$provider->id, ['label' => 'Twilio Production'])
        ->assertOk();

    expect(DB::table('integration_providers')->where('id', $provider->id)->value('credentials'))->toBe($ciphertext)
        ->and($provider->refresh()->label)->toBe('Twilio Production');
});

test('the capability and driver of a provider cannot be repointed through the API', function (): void {
    $provider = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $admin = adminWithRoles($this, ['super_admin'], 'repointer@example.com');

    $this->withToken($admin['token'])
        ->putJson('/api/v1/admin/integrations/providers/'.$provider->id, [
            'label' => 'Renamed',
            'driver' => 'log',
            'capability' => 'sms',
        ])
        ->assertOk();

    expect($provider->refresh()->driver)->toBe('twilio');
});

test('swapping the default provider is atomic and leaves exactly one default', function (): void {
    activateTwilio();
    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $admin = adminWithRoles($this, ['super_admin'], 'swapper@example.com');

    $this->withToken($admin['token'])
        ->postJson('/api/v1/admin/integrations/providers/'.$twilio->id.'/default')
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    expect(IntegrationProvider::query()->where('is_default', true)->count())->toBe(1)
        ->and(IntegrationProvider::query()->where('is_default', true)->first()->driver)->toBe('twilio');
});

test('an inactive provider cannot be made the default', function (): void {
    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $admin = adminWithRoles($this, ['super_admin'], 'inactive-default@example.com');

    $this->withToken($admin['token'])
        ->postJson('/api/v1/admin/integrations/providers/'.$twilio->id.'/default')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PROVIDER_INACTIVE');

    expect(IntegrationProvider::query()->where('is_default', true)->first()->driver)->toBe('log');
});

test('the usage endpoint reports attempts without message content', function (): void {
    $this->dispatcher->send(new SmsMessage('+15551234567', 'confidential body'));
    $admin = adminWithRoles($this, ['super_admin'], 'usage-reader@example.com');

    $response = $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/integrations/usage')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.driver'))->toBe('log')
        ->and($response->json('data.0.status'))->toBe('success')
        ->and($response->getContent())->not->toContain('confidential body')
        ->and($response->getContent())->not->toContain('+15551234567');
});
