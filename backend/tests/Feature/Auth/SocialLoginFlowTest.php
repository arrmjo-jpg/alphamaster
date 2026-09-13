<?php

declare(strict_types=1);

use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Exceptions\SocialAuthenticationForAdministratorException;
use App\Modules\Auth\Models\MfaMethod;
use App\Modules\Auth\Support\AuthCookie;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\SocialIdentity;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\FakeGoogle;

/*
 * Social login for user accounts, end to end (ADR 0050).
 *
 * Each sign-in goes the whole way: authorize through the API, a real signed ID token from
 * the fake provider, the callback. The only thing faked is the network.
 */

uses(RefreshDatabase::class);

const SOCIAL_CALLBACK = '/api/v1/auth/social/google/callback';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);
});

/**
 * Authorize, have the provider vouch for these claims, and post the callback.
 *
 * @param  array<string, mixed>  $claims
 * @param  array<string, mixed>  $header
 */
function socialSignIn(mixed $test, array $claims = [], array $header = [], ?OpenSSLAsymmetricKey $key = null)
{
    $flow = FakeGoogle::authorize($test);

    FakeGoogle::respondWith(FakeGoogle::idToken(FakeGoogle::claims($flow['nonce'], $claims), $header, $key));

    return $test->postJson(SOCIAL_CALLBACK, [
        'code' => 'authorization-code-from-google',
        'state' => $flow['state'],
        'code_verifier' => $flow['verifier'],
    ]);
}

function socialAccount(array $attributes): User
{
    return makeAccount($attributes);
}

function linkedGoogleIdentity(User $user, string $subject, bool $linked = true): SocialIdentity
{
    return SocialIdentity::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_subject' => $subject,
        'linked_at' => now(),
        'unlinked_at' => $linked ? null : now(),
    ]);
}

/**
 * @return array<int, array{subject: string|null, outcome: string, context: array<string, mixed>|null}>
 */
function socialAudit(string $action): array
{
    return DB::table('audit_records')->where('action', $action)->get()
        ->map(static fn (object $row): array => [
            'subject' => $row->subject,
            'outcome' => $row->outcome,
            'context' => is_string($row->context) ? json_decode($row->context, true) : null,
        ])->all();
}

// ── Which providers are offered ──────────────────────────────────────────────

test('no provider is offered while social login is off', function (): void {
    FakeGoogle::configure(enabled: false);

    $this->getJson('/api/v1/auth/social/providers')->assertOk()->assertJsonPath('data', []);
});

test('an effective provider is offered by key and label', function (): void {
    FakeGoogle::configure();

    $this->getJson('/api/v1/auth/social/providers')->assertOk()
        ->assertJsonPath('data', [['key' => 'google', 'label' => 'Google']]);
});

test('a provider that is switched on but not fully configured is not offered', function (): void {
    FakeGoogle::configure(withCredential: false);
    $this->getJson('/api/v1/auth/social/providers')->assertJsonPath('data', []);

    FakeGoogle::configure(clientId: '');
    $this->getJson('/api/v1/auth/social/providers')->assertJsonPath('data', []);

    FakeGoogle::configure(active: false);
    $this->getJson('/api/v1/auth/social/providers')->assertJsonPath('data', []);
});

// ── Authorize ────────────────────────────────────────────────────────────────

test('authorizing sends the person to Google with the platform state, nonce and the client challenge', function (): void {
    FakeGoogle::configure();

    $flow = FakeGoogle::authorize($this);
    parse_str((string) parse_url($flow['url'], PHP_URL_QUERY), $query);

    expect($flow['url'])->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['client_id'])->toBe(FakeGoogle::CLIENT_ID)
        ->and($query['redirect_uri'])->toBe(FakeGoogle::REDIRECT_URI)
        ->and($query['response_type'])->toBe('code')
        ->and($query['code_challenge'])->toBe(FakeGoogle::challenge($flow['verifier']))
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($flow['state'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($flow['nonce'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($flow['url'])->not->toContain(FakeGoogle::CLIENT_CREDENTIAL)
        ->and($flow['url'])->not->toContain($flow['verifier']);
});

test('authorizing is refused while social login is off, for an unknown provider, and for an inactive one', function (): void {
    $body = [
        'redirect_uri' => FakeGoogle::REDIRECT_URI,
        'code_challenge' => FakeGoogle::challenge(FakeGoogle::verifier()),
        'code_challenge_method' => 'S256',
    ];

    FakeGoogle::configure(enabled: false);
    $this->postJson('/api/v1/auth/social/google/authorize', $body)
        ->assertStatus(404)->assertJsonPath('error.code', 'SOCIAL_PROVIDER_UNAVAILABLE');

    FakeGoogle::configure();
    $this->postJson('/api/v1/auth/social/github/authorize', $body)
        ->assertStatus(404)->assertJsonPath('error.code', 'SOCIAL_PROVIDER_UNAVAILABLE');

    FakeGoogle::configure(active: false);
    $this->postJson('/api/v1/auth/social/google/authorize', $body)
        ->assertStatus(404)->assertJsonPath('error.code', 'SOCIAL_PROVIDER_UNAVAILABLE');
});

test('a redirect URI that is not an exact allowed entry is refused', function (): void {
    FakeGoogle::configure();

    foreach ([
        'https://attacker.example.test/auth/social/callback',
        FakeGoogle::REDIRECT_URI.'/extra',
        FakeGoogle::REDIRECT_URI.'?next=https://attacker.example.test',
        strtoupper(FakeGoogle::REDIRECT_URI),
    ] as $uri) {
        $this->postJson('/api/v1/auth/social/google/authorize', [
            'redirect_uri' => $uri,
            'code_challenge' => FakeGoogle::challenge(FakeGoogle::verifier()),
            'code_challenge_method' => 'S256',
        ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_REDIRECT_URI');
    }
});

test('a plain PKCE challenge is refused', function (): void {
    FakeGoogle::configure();

    $this->postJson('/api/v1/auth/social/google/authorize', [
        'redirect_uri' => FakeGoogle::REDIRECT_URI,
        'code_challenge' => FakeGoogle::verifier(),
        'code_challenge_method' => 'plain',
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

// ── Registration ─────────────────────────────────────────────────────────────

test('a first sign-in creates a user account, linked, with nothing verified locally and no password', function (): void {
    FakeGoogle::configure();

    $response = socialSignIn($this, ['sub' => 'new-person-subject', 'email' => 'New.Person@Example.test']);

    $response->assertStatus(201)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.abilities', ['user:access'])
        ->assertCookie(AuthCookie::NAME);

    $user = User::query()->where('email', 'new.person@example.test')->firstOrFail();

    expect($user->account_type)->toBe(AccountType::USER)
        ->and($user->email_verified_at)->toBeNull()
        ->and(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->toBeNull()
        ->and($user->password)->toBeNull()
        ->and(SocialIdentity::query()->where('user_id', $user->id)->linked()->pluck('provider_subject')->all())
        ->toBe(['new-person-subject']);
});

test('a social registration is recorded as a social account creation and a link', function (): void {
    FakeGoogle::configure();

    socialSignIn($this, ['sub' => 'recorded-subject', 'email' => 'recorded@example.test'])->assertStatus(201);

    $user = User::query()->where('email', 'recorded@example.test')->firstOrFail();
    $created = socialAudit(AuditAction::ACCOUNT_CREATED);
    $linked = socialAudit(AuditAction::ACCOUNT_SOCIAL_LINKED);

    expect($created)->toHaveCount(1)
        ->and($created[0]['subject'])->toBe($user->id)
        ->and($created[0]['context'])->toBe(['active' => true, 'source' => 'social', 'provider' => 'google'])
        ->and($linked)->toHaveCount(1)
        ->and($linked[0]['context']['provider'])->toBe('google');
});

test('registration is refused while public registration is off', function (): void {
    FakeGoogle::configure();
    app(SettingServiceInterface::class)->set('auth', 'registration_enabled', false);
    Cache::flush();

    socialSignIn($this, ['email' => 'closed@example.test'])
        ->assertStatus(403)->assertJsonPath('error.code', 'REGISTRATION_CLOSED');

    expect(User::query()->where('email', 'closed@example.test')->exists())->toBeFalse();
});

test('an address the provider does not vouch for creates nothing', function (): void {
    FakeGoogle::configure();

    socialSignIn($this, ['email' => 'unverified@example.test', 'email_verified' => false])
        ->assertStatus(403)->assertJsonPath('error.code', 'SOCIAL_SIGN_IN_REFUSED');

    socialSignIn($this, ['sub' => 'no-address-subject', 'email' => null])
        ->assertStatus(403)->assertJsonPath('error.code', 'SOCIAL_SIGN_IN_REFUSED');

    expect(User::query()->where('email', 'unverified@example.test')->exists())->toBeFalse()
        ->and(SocialIdentity::query()->count())->toBe(0);
});

// ── Signing in an existing identity ──────────────────────────────────────────

test('a linked identity signs in to its account with an ordinary user token', function (): void {
    FakeGoogle::configure();
    $user = socialAccount(['email' => 'returning@example.test']);
    $identity = linkedGoogleIdentity($user, 'returning-subject');

    $response = socialSignIn($this, ['sub' => 'returning-subject', 'email' => 'someone-else@example.test']);

    $response->assertOk()->assertJsonPath('data.abilities', ['user:access']);

    $token = PersonalAccessToken::findToken((string) $response->json('data.token'));

    expect($token?->tokenable_id)->toBe($user->id)
        ->and($token?->abilities)->toBe(['user:access'])
        ->and($token?->name)->toBe('social-sign-in')
        ->and($identity->fresh()->last_used_at)->not->toBeNull()
        ->and(User::query()->count())->toBe(1);

    resetClient($this);

    $this->withToken((string) $response->json('data.token'))->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.id', $user->id);
});

test('an account with a second factor is challenged rather than handed a token', function (): void {
    FakeGoogle::configure();
    $user = socialAccount(['email' => 'has-mfa@example.test']);
    linkedGoogleIdentity($user, 'mfa-subject');

    MfaMethod::query()->create([
        'user_id' => $user->id,
        'type' => MfaType::TOTP,
        'secret' => 'ABCDEFGHIJKLMNOP',
        'confirmed_at' => now(),
    ]);

    socialSignIn($this, ['sub' => 'mfa-subject'])
        ->assertOk()
        ->assertJsonPath('data.mfa_required', true)
        ->assertJsonMissingPath('data.token');

    expect($user->tokens()->count())->toBe(0);
});

test('an unlinked identity signs nobody in, creates nothing, and stays reserved', function (): void {
    FakeGoogle::configure();
    $owner = socialAccount(['email' => 'reserved-owner@example.test']);
    linkedGoogleIdentity($owner, 'reserved-subject', linked: false);

    // A different, verified address that belongs to no account: without the reservation
    // this would register a second account for the same Google identity.
    socialSignIn($this, ['sub' => 'reserved-subject', 'email' => 'fresh-address@example.test'])
        ->assertStatus(403)->assertJsonPath('error.code', 'SOCIAL_SIGN_IN_REFUSED');

    expect(User::query()->count())->toBe(1)
        ->and(SocialIdentity::query()->where('provider_subject', 'reserved-subject')->pluck('user_id')->all())->toBe([$owner->id])
        ->and($owner->tokens()->count())->toBe(0);
});

test('a disabled account is not signed in through its identity', function (): void {
    FakeGoogle::configure();
    $user = socialAccount(['email' => 'disabled@example.test', 'is_active' => false]);
    linkedGoogleIdentity($user, 'disabled-subject');

    socialSignIn($this, ['sub' => 'disabled-subject'])
        ->assertStatus(403)->assertJsonPath('error.code', 'SOCIAL_SIGN_IN_REFUSED');

    expect($user->tokens()->count())->toBe(0);
});

// ── No account is taken over by address ──────────────────────────────────────

test('an address that belongs to a user is not linked automatically', function (): void {
    FakeGoogle::configure();
    $user = socialAccount(['email' => 'existing@example.test']);

    socialSignIn($this, ['sub' => 'stranger-subject', 'email' => 'existing@example.test'])
        ->assertStatus(409)->assertJsonPath('error.code', 'SOCIAL_IDENTITY_NOT_LINKED');

    expect(SocialIdentity::query()->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0);
});

test('an administrator’s address is refused and recorded, whatever the provider says about it', function (): void {
    FakeGoogle::configure();
    $admin = socialAccount(['email' => 'admin@example.test', 'account_type' => AccountType::ADMIN]);

    // Different case, and not vouched for: the refusal comes before either matters.
    socialSignIn($this, ['sub' => 'aimed-at-admin', 'email' => 'ADMIN@example.test', 'email_verified' => false])
        ->assertStatus(403)->assertJsonPath('error.code', 'SOCIAL_SIGN_IN_REFUSED');

    socialSignIn($this, ['sub' => 'aimed-at-admin-2', 'email' => 'admin@example.test'])
        ->assertStatus(403)->assertJsonPath('error.code', 'SOCIAL_SIGN_IN_REFUSED');

    $records = socialAudit(AuditAction::AUTH_SOCIAL_REFUSED);

    expect($records)->toHaveCount(2)
        ->and($records[0]['subject'])->toBe($admin->id)
        ->and($records[0]['outcome'])->toBe('failed')
        ->and($records[0]['context'])->toBe(['provider' => 'google', 'reason' => 'admin_email_match'])
        ->and(SocialIdentity::query()->count())->toBe(0)
        ->and($admin->tokens()->count())->toBe(0);
});

test('refusals that do not concern an administrator stay out of the audit trail', function (): void {
    FakeGoogle::configure();
    $user = socialAccount(['email' => 'inactive-user@example.test', 'is_active' => false]);
    linkedGoogleIdentity($user, 'inactive-subject');

    socialSignIn($this, ['sub' => 'inactive-subject'])->assertStatus(403);
    socialSignIn($this, ['sub' => 'unverified-subject', 'email' => 'x@example.test', 'email_verified' => false])->assertStatus(403);

    expect(socialAudit(AuditAction::AUTH_SOCIAL_REFUSED))->toBe([]);
});

test('a social sign-in token is never issued to an administrator', function (): void {
    $admin = socialAccount(['email' => 'no-social-token@example.test', 'account_type' => AccountType::ADMIN]);

    expect(fn () => app(AuthServiceContract::class)->issueSocialToken($admin))
        ->toThrow(SocialAuthenticationForAdministratorException::class);

    expect($admin->tokens()->count())->toBe(0);
});

// ── State and PKCE ───────────────────────────────────────────────────────────

test('a state cannot be used twice', function (): void {
    FakeGoogle::configure();
    $flow = FakeGoogle::authorize($this);
    FakeGoogle::respondWith(FakeGoogle::idToken(FakeGoogle::claims($flow['nonce'], ['email' => 'once@example.test'])));

    $payload = ['code' => 'code-once', 'state' => $flow['state'], 'code_verifier' => $flow['verifier']];

    $this->postJson(SOCIAL_CALLBACK, $payload)->assertStatus(201);

    resetClient($this);

    $this->postJson(SOCIAL_CALLBACK, $payload)
        ->assertStatus(422)->assertJsonPath('error.code', 'SOCIAL_STATE_INVALID');

    expect(FakeGoogle::tokenRequests())->toBe(1);
});

test('a wrong PKCE verifier is refused before the code is redeemed, and spends the state', function (): void {
    FakeGoogle::configure();
    $flow = FakeGoogle::authorize($this);
    FakeGoogle::respondWith(FakeGoogle::idToken(FakeGoogle::claims($flow['nonce'])));

    $this->postJson(SOCIAL_CALLBACK, [
        'code' => 'code',
        'state' => $flow['state'],
        'code_verifier' => FakeGoogle::verifier(),
    ])->assertStatus(422)->assertJsonPath('error.code', 'SOCIAL_STATE_INVALID');

    // The right verifier afterwards does not help: the state is gone.
    $this->postJson(SOCIAL_CALLBACK, [
        'code' => 'code',
        'state' => $flow['state'],
        'code_verifier' => $flow['verifier'],
    ])->assertStatus(422);

    expect(FakeGoogle::tokenRequests())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

test('a state nobody issued is refused', function (): void {
    FakeGoogle::configure();
    Http::fake();

    $this->postJson(SOCIAL_CALLBACK, [
        'code' => 'code',
        'state' => str_repeat('a', 64),
        'code_verifier' => FakeGoogle::verifier(),
    ])->assertStatus(422)->assertJsonPath('error.code', 'SOCIAL_STATE_INVALID');

    Http::assertNothingSent();
});

test('a state issued for one provider cannot finish a sign-in at another', function (): void {
    FakeGoogle::configure();
    $flow = FakeGoogle::authorize($this);
    Http::fake();

    $this->postJson('/api/v1/auth/social/github/callback', [
        'code' => 'code',
        'state' => $flow['state'],
        'code_verifier' => $flow['verifier'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'SOCIAL_STATE_INVALID');

    Http::assertNothingSent();
});

test('the code is redeemed against the redirect URI the platform issued, whatever the callback says', function (): void {
    FakeGoogle::configure();
    $flow = FakeGoogle::authorize($this);
    FakeGoogle::respondWith(FakeGoogle::idToken(FakeGoogle::claims($flow['nonce'], ['email' => 'redirect@example.test'])));

    $this->postJson(SOCIAL_CALLBACK, [
        'code' => 'code',
        'state' => $flow['state'],
        'code_verifier' => $flow['verifier'],
        'redirect_uri' => 'https://attacker.example.test/steal',
    ])->assertStatus(201);

    Http::assertSent(static fn ($request): bool => $request->url() === FakeGoogle::TOKEN_URL
        && $request['redirect_uri'] === FakeGoogle::REDIRECT_URI
        && $request['code_verifier'] === $flow['verifier']);
});

// ── The provider's answer ────────────────────────────────────────────────────

test('an ID token that fails validation establishes no identity', function (array $claims, array $header, bool $foreignKey, string $code): void {
    FakeGoogle::configure();

    socialSignIn($this, array_merge(['email' => 'spoofed@example.test'], $claims), $header, $foreignKey ? FakeGoogle::otherKey() : null)
        ->assertStatus(502)->assertJsonPath('error.code', 'SOCIAL_PROVIDER_ERROR');

    expect(User::query()->where('email', 'spoofed@example.test')->exists())->toBeFalse()
        ->and(IntegrationUsageLog::query()->where('capability', 'social_login')->latest('created_at')->value('error_code'))->toBe($code);
})->with([
    'another client' => [['aud' => 'someone-else.apps.googleusercontent.com', 'azp' => 'someone-else.apps.googleusercontent.com'], [], false, 'ID_TOKEN_AUDIENCE'],
    'another issuer' => [['iss' => 'https://issuer.example.test'], [], false, 'ID_TOKEN_ISSUER'],
    'a key Google never published' => [[], [], true, 'ID_TOKEN_SIGNATURE'],
    'a nonce from another flow' => [['nonce' => str_repeat('b', 64)], [], false, 'ID_TOKEN_NONCE'],
    'an expired token' => [['exp' => time() - 3600, 'iat' => time() - 7200], [], false, 'ID_TOKEN_EXPIRED'],
    'an unsigned token' => [[], ['alg' => 'none'], false, 'ID_TOKEN_ALGORITHM'],
]);

test('a code Google refuses establishes nothing, and its explanation is not copied', function (): void {
    FakeGoogle::configure();
    $flow = FakeGoogle::authorize($this);
    FakeGoogle::refuseCode();

    $this->postJson(SOCIAL_CALLBACK, [
        'code' => 'refused-code',
        'state' => $flow['state'],
        'code_verifier' => $flow['verifier'],
    ])->assertStatus(502)->assertJsonPath('error.code', 'SOCIAL_PROVIDER_ERROR');

    $log = IntegrationUsageLog::query()->where('capability', 'social_login')->firstOrFail();

    expect($log->error_code)->toBe('TOKEN_REJECTED')
        ->and($log->error_message)->toContain('invalid_grant')
        ->and($log->error_message)->not->toContain('must not be copied');
});

test('a provider switched off mid-flow finishes nothing', function (): void {
    $provider = FakeGoogle::configure();
    $flow = FakeGoogle::authorize($this);

    // Switched off the way an operator would, without clearing the cache: the flow's
    // state lives there, and the point is that it is still valid when the callback comes.
    $provider->forceFill(['is_active' => false])->save();
    Http::fake();

    $this->postJson(SOCIAL_CALLBACK, [
        'code' => 'code',
        'state' => $flow['state'],
        'code_verifier' => $flow['verifier'],
    ])->assertStatus(404)->assertJsonPath('error.code', 'SOCIAL_PROVIDER_UNAVAILABLE');

    Http::assertNothingSent();
});

// ── Limits, and what is written down ────────────────────────────────────────

test('repeated failed callbacks are throttled', function (): void {
    FakeGoogle::configure();
    Http::fake();

    $attempt = fn () => $this->postJson(SOCIAL_CALLBACK, [
        'code' => 'code',
        'state' => bin2hex(random_bytes(32)),
        'code_verifier' => FakeGoogle::verifier(),
    ]);

    for ($i = 0; $i < 5; $i++) {
        $attempt()->assertStatus(422);
    }

    $attempt()->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS')
        ->assertHeader('Retry-After');
});

test('nothing the flow handles is written to the audit trail or the usage log', function (): void {
    FakeGoogle::configure();
    socialAccount(['email' => 'secretive-admin@example.test', 'account_type' => AccountType::ADMIN]);

    $flow = FakeGoogle::authorize($this);
    $idToken = FakeGoogle::idToken(FakeGoogle::claims($flow['nonce'], ['sub' => 'secret-subject-value', 'email' => 'secretive@example.test']));
    FakeGoogle::respondWith($idToken);

    $this->postJson(SOCIAL_CALLBACK, [
        'code' => 'secret-authorization-code',
        'state' => $flow['state'],
        'code_verifier' => $flow['verifier'],
    ])->assertStatus(201);

    socialSignIn($this, ['sub' => 'aimed', 'email' => 'secretive-admin@example.test'])->assertStatus(403);

    $written = json_encode([
        DB::table('audit_records')->get()->all(),
        DB::table('integration_usage_logs')->get()->all(),
    ]);

    foreach ([
        'secret-authorization-code',
        $flow['state'],
        $flow['nonce'],
        $flow['verifier'],
        $idToken,
        FakeGoogle::ISSUED_ACCESS_TOKEN,
        FakeGoogle::CLIENT_CREDENTIAL,
        'secret-subject-value',
        'secretive@example.test',
        'secretive-admin@example.test',
    ] as $secret) {
        expect($written)->not->toContain($secret);
    }
});

test('no provider token is stored anywhere on the identity', function (): void {
    FakeGoogle::configure();

    socialSignIn($this, ['sub' => 'no-token-subject', 'email' => 'no-token@example.test'])->assertStatus(201);

    expect(json_encode(DB::table('social_identities')->get()->all()))
        ->not->toContain(FakeGoogle::ISSUED_ACCESS_TOKEN);
});

test('every social login message reads in both languages', function (): void {
    $keys = [
        'api.error.auth.social_provider_unavailable',
        'api.error.auth.social_invalid_redirect_uri',
        'api.error.auth.social_state_invalid',
        'api.error.auth.social_provider_error',
        'api.error.auth.social_sign_in_refused',
        'api.error.auth.social_identity_not_linked',
        'api.error.auth.social_registration_closed',
        'api.error.auth.password_reset_invalid',
        'api.error.user.promotion_refused_social_identity',
        'api.error.user.social_identity_administrator',
        'api.error.user.social_identity_identity_in_use',
        'api.error.user.social_identity_provider_already_linked',
        'api.error.user.social_identity_last_sign_in_method',
        'api.error.user.social_identity_not_linked',
    ];

    foreach ($keys as $key) {
        $english = __($key, [], 'en');
        $arabic = __($key, [], 'ar');

        expect($english)->not->toBe($key, $key.' is missing in English')
            ->and($arabic)->not->toBe($key, $key.' is missing in Arabic')
            ->and($arabic)->not->toBe($english, $key.' is not translated');
    }
});

test('nothing the client sends names the identity, the address, the account type or the provider', function (): void {
    FakeGoogle::configure();
    $flow = FakeGoogle::authorize($this);
    FakeGoogle::respondWith(FakeGoogle::idToken(FakeGoogle::claims($flow['nonce'], [
        'sub' => 'subject-google-signed',
        'email' => 'signed@example.test',
    ])));

    $this->postJson(SOCIAL_CALLBACK, [
        'code' => 'authorization-code',
        'state' => $flow['state'],
        'code_verifier' => $flow['verifier'],
        'provider' => 'github',
        'provider_subject' => 'subject-chosen-by-client',
        'sub' => 'subject-chosen-by-client',
        'email' => 'chosen@example.test',
        'account_type' => 'admin',
        'user_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
    ])->assertStatus(201);

    $user = User::query()->where('email', 'signed@example.test')->firstOrFail();

    expect($user->account_type)->toBe(AccountType::USER)
        ->and(SocialIdentity::query()->pluck('provider_subject')->all())->toBe(['subject-google-signed'])
        ->and(SocialIdentity::query()->pluck('provider')->all())->toBe(['google'])
        ->and(User::query()->where('email', 'chosen@example.test')->exists())->toBeFalse();
});

test('no provider is offered while no return address is usable', function (): void {
    FakeGoogle::configure();
    app(SettingServiceInterface::class)->set('auth', 'social_redirect_uris', []);
    Cache::flush();

    $this->getJson('/api/v1/auth/social/providers')->assertOk()->assertJsonPath('data', []);
});

test('a social sign-in token comes from the same issuance as every other access token', function (): void {
    FakeGoogle::configure();
    $user = socialAccount(['email' => 'same-issuance@example.test']);
    linkedGoogleIdentity($user, 'same-issuance-subject');

    $token = PersonalAccessToken::findToken((string) socialSignIn($this, ['sub' => 'same-issuance-subject'])->assertOk()->json('data.token'));

    expect($token?->abilities)->toBe(['user:access'])
        ->and($token?->can('admin:access'))->toBeFalse()
        ->and($token?->can('mfa:enrol'))->toBeFalse()
        ->and($token?->can('email:verify'))->toBeFalse();
});
