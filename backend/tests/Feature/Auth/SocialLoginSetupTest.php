<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeGoogle;

/*
 * The two addresses social login needs from an operator — the return addresses and the
 * password reset page — configured through the Admin API, validated against
 * ClientUrlPolicy when written and again when used, and shown back to the operator as
 * what to register with Google (ADR 0050 §10–§12).
 *
 * Addresses here are fixtures under the reserved `.test` domain. The platform ships with
 * none, and the first test proves it invents none.
 */

uses(RefreshDatabase::class);

const SETUP_ENDPOINT = '/api/v1/admin/auth/social-login/setup';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    $this->token = adminToken(roles: ['super_admin']);
});

function putAuthSettings(mixed $test, array $values)
{
    return $test->withToken($test->token)
        ->withHeaders(['If-Match' => settingsVersion('auth')])
        ->putJson('/api/v1/admin/settings/auth', ['settings' => $values]);
}

/**
 * Write a value the way an earlier save, a restore or a migration would have left it,
 * without this environment's validation.
 */
function storeAuthSettingDirectly(string $key, mixed $value): void
{
    DB::table('settings')->where('group', 'auth')->where('key', $key)
        ->update(['value' => is_array($value) ? json_encode($value) : $value]);

    Cache::flush();
}

function pretendProduction(): void
{
    app()->detectEnvironment(static fn (): string => 'production');
}

function activateGoogleProvider(): void
{
    $provider = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::SOCIAL_LOGIN)
        ->where('driver', 'google')
        ->firstOrFail();

    $provider->setCredentials(['client_secret' => FakeGoogle::CLIENT_CREDENTIAL]);
    $provider->forceFill(['is_active' => true, 'settings' => ['client_id' => FakeGoogle::CLIENT_ID]])->save();

    Cache::flush();
}

/**
 * @return array<int, string>
 */
function setupIssueCodes(mixed $response): array
{
    return array_column((array) $response->json('data.issues'), 'code');
}

// ── Nothing is assumed ───────────────────────────────────────────────────────

test('a fresh installation shows what is missing and offers no address of its own', function (): void {
    $response = $this->withToken($this->token)->getJson(SETUP_ENDPOINT)->assertOk();

    $response->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.ready', false)
        ->assertJsonPath('data.redirect_uris', [])
        ->assertJsonPath('data.register_in_provider_console', [])
        ->assertJsonPath('data.password_reset_url.value', null)
        ->assertJsonPath('data.password_reset_url.usable', false)
        ->assertJsonPath('data.password_reset_url.problem', 'missing')
        ->assertJsonPath('data.providers.0.key', 'google')
        ->assertJsonPath('data.providers.0.effective', false)
        ->assertJsonPath('data.providers.0.client_id_configured', false)
        ->assertJsonPath('data.providers.0.client_secret_configured', false)
        ->assertJsonPath('data.providers.0.missing', ['client_id', 'client_secret']);

    expect(setupIssueCodes($response))->toBe(['no_redirect_uris', 'password_reset_url_missing', 'no_effective_provider'])
        // No example, no fallback, no localhost: nothing that looks like an address at all.
        ->and($response->content())->not->toContain('://')
        ->and($response->content())->not->toContain('localhost');
});

test('both settings ship empty, with no default address', function (): void {
    expect(setting('auth.social_redirect_uris'))->toBe([])
        ->and(setting('auth.password_reset_url'))->toBeNull();
});

// ── Configured through the Admin API ─────────────────────────────────────────

test('an operator sets both addresses in the settings API, and setup lists exactly what to register with Google', function (): void {
    $uris = ['https://client.example.test/auth/callback', 'com.example.app:/oauth2redirect'];

    putAuthSettings($this, [
        'social_redirect_uris' => $uris,
        'password_reset_url' => 'https://client.example.test/reset-password',
        'social_login_enabled' => true,
    ])->assertOk();

    $this->withToken($this->token)
        ->putJson('/api/v1/admin/integrations/providers/'.IntegrationProvider::query()->where('driver', 'google')->value('id'), [
            'settings' => ['client_id' => FakeGoogle::CLIENT_ID],
            'credentials' => ['client_secret' => FakeGoogle::CLIENT_CREDENTIAL],
            'is_active' => true,
        ])->assertOk();

    $response = $this->withToken($this->token)->getJson(SETUP_ENDPOINT)->assertOk();

    $response->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.ready', true)
        ->assertJsonPath('data.register_in_provider_console', $uris)
        ->assertJsonPath('data.redirect_uris.0.usable', true)
        ->assertJsonPath('data.redirect_uris.1.usable', true)
        ->assertJsonPath('data.password_reset_url.value', 'https://client.example.test/reset-password')
        ->assertJsonPath('data.password_reset_url.usable', true)
        ->assertJsonPath('data.providers.0.effective', true)
        ->assertJsonPath('data.providers.0.client_id_configured', true)
        ->assertJsonPath('data.providers.0.client_secret_configured', true)
        ->assertJsonPath('data.providers.0.missing', []);

    expect(setupIssueCodes($response))->toBe([])
        ->and($response->content())->not->toContain(FakeGoogle::CLIENT_CREDENTIAL);
});

test('an unsafe return address is refused when written, with its reason', function (mixed $uris, string $message): void {
    $response = putAuthSettings($this, ['social_redirect_uris' => $uris]);

    $response->assertStatus(422);

    expect($response->content())->toContain($message)
        ->and(setting('auth.social_redirect_uris'))->toBe([]);
})->with([
    'a fragment' => [['https://client.example.test/auth/callback#done'], 'it must not contain a fragment (#).'],
    'a wildcard' => [['https://*.example.test/auth/callback'], 'Addresses are matched exactly.'],
    'credentials' => [['https://user:secret@client.example.test/auth/callback'], 'user name or password'],
    'a javascript address' => [['javascript:alert(1)'], 'its scheme is not accepted'],
    'a relative path' => [['/auth/callback'], 'complete address'],
    'the same address twice' => [['https://client.example.test/auth/callback', 'https://client.example.test/auth/callback'], 'listed more than once'],
    'a map rather than a list' => [['web' => 'https://client.example.test/auth/callback'], 'must be a list of addresses'],
]);

test('an unsafe reset page is refused when written', function (string $url, string $message): void {
    $response = putAuthSettings($this, ['password_reset_url' => $url]);

    $response->assertStatus(422);

    expect($response->content())->toContain($message)
        ->and(setting('auth.password_reset_url'))->toBeNull();
})->with([
    'a fragment' => ['https://client.example.test/reset-password#form', 'fragment'],
    'credentials' => ['https://user:secret@client.example.test/reset-password', 'user name or password'],
]);

test('outside production a developer may point both at a local client', function (): void {
    putAuthSettings($this, [
        'social_redirect_uris' => ['http://localhost/auth/callback'],
        'password_reset_url' => 'http://localhost/reset-password',
    ])->assertOk();

    $this->withToken($this->token)->getJson(SETUP_ENDPOINT)
        ->assertJsonPath('data.production', false)
        ->assertJsonPath('data.register_in_provider_console', ['http://localhost/auth/callback'])
        ->assertJsonPath('data.password_reset_url.usable', true);
});

// ── Production ───────────────────────────────────────────────────────────────

test('in production plain http and local addresses are refused when written', function (): void {
    pretendProduction();

    putAuthSettings($this, ['social_redirect_uris' => ['http://localhost/auth/callback']])->assertStatus(422);
    putAuthSettings($this, ['social_redirect_uris' => ['http://client.example.test/auth/callback']])->assertStatus(422);
    putAuthSettings($this, ['password_reset_url' => 'https://127.0.0.1/reset-password'])->assertStatus(422);
    putAuthSettings($this, ['password_reset_url' => 'http://client.example.test/reset-password'])->assertStatus(422);

    expect(setting('auth.social_redirect_uris'))->toBe([])
        ->and(setting('auth.password_reset_url'))->toBeNull();

    putAuthSettings($this, [
        'social_redirect_uris' => ['https://client.example.test/auth/callback'],
        'password_reset_url' => 'https://client.example.test/reset-password',
    ])->assertOk();
});

test('in production a local address saved earlier is not trusted by setup, sign-in or recovery', function (): void {
    FakeGoogle::configure();
    storeAuthSettingDirectly('social_redirect_uris', ['http://localhost/auth/callback']);
    storeAuthSettingDirectly('password_reset_url', 'http://localhost/reset-password');

    pretendProduction();

    $setup = $this->withToken($this->token)->getJson(SETUP_ENDPOINT)->assertOk();

    $setup->assertJsonPath('data.production', true)
        ->assertJsonPath('data.ready', false)
        ->assertJsonPath('data.redirect_uris.0.uri', 'http://localhost/auth/callback')
        ->assertJsonPath('data.redirect_uris.0.usable', false)
        ->assertJsonPath('data.redirect_uris.0.problem', 'loopback')
        ->assertJsonPath('data.register_in_provider_console', [])
        ->assertJsonPath('data.password_reset_url.usable', false)
        ->assertJsonPath('data.password_reset_url.problem', 'loopback');

    expect(setupIssueCodes($setup))->toBe(['unusable_redirect_uri', 'password_reset_url_unusable']);

    resetClient($this);

    // A sign-in naming the stored address is refused as if it were not listed.
    $this->postJson('/api/v1/auth/social/google/authorize', [
        'redirect_uri' => 'http://localhost/auth/callback',
        'code_challenge' => FakeGoogle::challenge(FakeGoogle::verifier()),
        'code_challenge_method' => 'S256',
    ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_REDIRECT_URI');

    // And no reset link is built to it.
    Notification::fake();
    $this->withoutDefer();
    makeAccount(['email' => 'recovering@example.test']);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'recovering@example.test'])->assertOk();

    Notification::assertNothingSent();
});

test('in production social registration stays closed until the reset page is usable', function (): void {
    FakeGoogle::configure();
    pretendProduction();

    $signIn = function (string $subject, string $email) {
        resetClient($this);
        $flow = FakeGoogle::authorize($this);
        FakeGoogle::respondWith(FakeGoogle::idToken(FakeGoogle::claims($flow['nonce'], ['sub' => $subject, 'email' => $email])));

        return $this->postJson('/api/v1/auth/social/google/callback', [
            'code' => 'authorization-code',
            'state' => $flow['state'],
            'code_verifier' => $flow['verifier'],
        ]);
    };

    $signIn('production-subject-1', 'first@example.test')
        ->assertStatus(403)->assertJsonPath('error.code', 'REGISTRATION_CLOSED');

    expect(User::query()->where('email', 'first@example.test')->exists())->toBeFalse();

    app(SettingServiceInterface::class)->set('auth', 'password_reset_url', 'https://client.example.test/reset-password');
    Cache::flush();

    $signIn('production-subject-2', 'second@example.test')->assertStatus(201);
});

test('a reset link is built on the configured page, with the token and the address added', function (): void {
    putAuthSettings($this, ['password_reset_url' => 'https://client.example.test/reset-password?lang=ar'])->assertOk();

    Notification::fake();
    $this->withoutDefer();
    $user = makeAccount(['email' => 'link-shape@example.test']);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'link-shape@example.test'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, static function (ResetPassword $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, 'https://client.example.test/reset-password?lang=ar&token=')
            && str_contains($url, '&email='.rawurlencode('link-shape@example.test'));
    });
});

// ── Who may look ─────────────────────────────────────────────────────────────

test('setup is behind the permission that reads settings', function (): void {
    $this->withToken(adminToken())->getJson(SETUP_ENDPOINT)->assertStatus(403);

    resetClient($this);

    $regular = regularWithToken($this, 'not-an-operator@example.test');
    $this->withToken($regular['token'])->getJson(SETUP_ENDPOINT)->assertStatus(403);

    resetClient($this);

    $this->getJson(SETUP_ENDPOINT)->assertStatus(401);
});

test('setup never reads out a credential', function (): void {
    activateGoogleProvider();

    $body = $this->withToken($this->token)->getJson(SETUP_ENDPOINT)->assertOk()->content();

    $ciphertext = (string) DB::table('integration_providers')->where('driver', 'google')->value('credentials');

    expect($body)->not->toContain(FakeGoogle::CLIENT_CREDENTIAL)
        ->and($body)->not->toContain($ciphertext)
        ->and($body)->not->toContain('client_secret"');
});
