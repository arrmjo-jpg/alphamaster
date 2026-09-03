<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

const ADMIN_PASSWORD = 'admin-password';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);

    $this->admin = makeAccount([
        'name' => 'Mandatory Admin',
        'email' => 'mandatory@example.com',
        'password' => ADMIN_PASSWORD,
        'account_type' => AccountType::ADMIN,
        'is_active' => true,
    ]);
});

/**
 * Sign in and return the raw login payload.
 *
 * @return array<string, mixed>
 */
function adminLogin(mixed $test, string $email = 'mandatory@example.com'): array
{
    resetClient($test);

    return (array) $test->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => ADMIN_PASSWORD,
    ])->json('data');
}

// ─── The rule ─────────────────────────────────────────────────────────────────

test('an admin without MFA gets no access token, only an enrolment credential', function (): void {
    $data = adminLogin($this);

    expect($data['mfa_setup_required'])->toBeTrue()
        ->and($data['abilities'])->toBe([TokenAbility::MFA_ENROL->value])
        // The decisive assertion: nothing here is an access token.
        ->and($data)->not->toHaveKey('token')
        ->and($data)->not->toHaveKey('mfa_token');

    $issued = PersonalAccessToken::findToken($data['enrolment_token']);

    expect($issued->abilities)->toBe([TokenAbility::MFA_ENROL->value])
        ->and($issued->can(TokenAbility::ADMIN_ACCESS->value))->toBeFalse()
        ->and($issued->can(TokenAbility::USER_ACCESS->value))->toBeFalse();
});

test('the enrolment credential cannot reach the admin perimeter', function (): void {
    $token = adminLogin($this)['enrolment_token'];

    $this->withToken($token)->getJson('/api/v1/admin/settings')->assertStatus(403);
    $this->withToken($token)->putJson('/api/v1/admin/settings/general', [
        'settings' => ['site_name' => 'Should Not Work'],
    ])->assertStatus(403);

    expect(setting('general.site_name'))->toBe('AlphaMaster Enterprise');
});

test('the enrolment credential cannot act as the user either', function (): void {
    $token = adminLogin($this)['enrolment_token'];

    // Not a signed-in identity: it may enrol, and nothing else.
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(403);
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/auth/mfa')->assertStatus(403);
    $this->withToken($token)->deleteJson('/api/v1/auth/mfa', ['code' => '000000'])->assertStatus(403);
});

test('the enrolment credential can reach exactly the enrolment endpoints', function (): void {
    $token = adminLogin($this)['enrolment_token'];

    $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol')->assertOk();
});

test('completing enrolment upgrades the credential to a real admin token', function (): void {
    $token = adminLogin($this)['enrolment_token'];

    $secret = $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol')->json('data.secret');

    $response = $this->withToken($token)->postJson('/api/v1/auth/mfa/verify', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.abilities', [TokenAbility::ADMIN_ACCESS->value]);

    // The enrolment credential is burnt in the exchange.
    expect(PersonalAccessToken::findToken($token))->toBeNull();

    resetClient($this);
    $this->withToken($response->json('data.token'))
        ->getJson('/api/v1/admin/settings')
        ->assertOk();
});

test('an enrolled admin is challenged on subsequent sign-ins, never handed a token', function (): void {
    $result = signInAdminWithMfa($this, 'mandatory@example.com', ADMIN_PASSWORD);

    $data = adminLogin($this);

    expect($data['mfa_required'])->toBeTrue()
        ->and($data)->not->toHaveKey('token')
        ->and($data)->not->toHaveKey('enrolment_token');

    // And the challenge yields admin:access once satisfied.
    $issued = $this->postJson('/api/v1/auth/mfa/challenge', [
        'mfa_token' => $data['mfa_token'],
        'code' => $result['recovery'][0],
    ]);

    $issued->assertOk()->assertJsonPath('data.abilities', [TokenAbility::ADMIN_ACCESS->value]);
});

test('at no point does an unenrolled admin hold a token with admin:access', function (): void {
    adminLogin($this);

    // Every token in existence for this admin, at the one moment they could have one.
    $abilities = PersonalAccessToken::query()
        ->where('tokenable_id', $this->admin->id)
        ->pluck('abilities')
        ->flatten()
        ->all();

    expect($abilities)->toBe([TokenAbility::MFA_ENROL->value]);
});

// ─── Regular users are unaffected ─────────────────────────────────────────────

test('a regular user without MFA still signs in directly', function (): void {
    makeAccount([
        'name' => 'Plain',
        'email' => 'plain@example.com',
        'password' => ADMIN_PASSWORD,
        'account_type' => AccountType::USER,
        'is_active' => true,
    ]);

    $data = adminLogin($this, 'plain@example.com');

    expect($data['abilities'])->toBe([TokenAbility::USER_ACCESS->value])
        ->and($data)->not->toHaveKey('mfa_setup_required');
});

test('a regular user may still enrol voluntarily with an ordinary token', function (): void {
    makeAccount([
        'name' => 'Volunteer',
        'email' => 'volunteer@example.com',
        'password' => ADMIN_PASSWORD,
        'account_type' => AccountType::USER,
        'is_active' => true,
    ]);

    $token = adminLogin($this, 'volunteer@example.com')['token'];

    $secret = $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol')->json('data.secret');

    $response = $this->withToken($token)->postJson('/api/v1/auth/mfa/verify', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ]);

    $response->assertOk()->assertJsonPath('data.enabled', true);

    // No upgrade happens: they already held a full access token.
    expect($response->json('data.token'))->toBeNull()
        ->and(PersonalAccessToken::findToken($token))->not->toBeNull();
});

// ─── Disabling ────────────────────────────────────────────────────────────────

test('an admin disabling MFA loses every token and must enrol again', function (): void {
    $result = signInAdminWithMfa($this, 'mandatory@example.com', ADMIN_PASSWORD);

    $this->withToken($result['token'])
        ->deleteJson('/api/v1/auth/mfa', ['code' => $result['recovery'][0]])
        ->assertOk()
        ->assertJsonPath('data.tokens_revoked', true);

    // The invariant holds continuously: no surviving token grants admin access.
    expect(PersonalAccessToken::query()->where('tokenable_id', $this->admin->id)->count())->toBe(0);

    resetClient($this);
    $this->withToken($result['token'])->getJson('/api/v1/admin/settings')->assertStatus(401);

    // Next sign-in walks them back through enrolment.
    expect(adminLogin($this)['mfa_setup_required'])->toBeTrue();
});

test('a regular user disabling MFA keeps their session', function (): void {
    makeAccount([
        'name' => 'Keeper',
        'email' => 'keeper@example.com',
        'password' => ADMIN_PASSWORD,
        'account_type' => AccountType::USER,
        'is_active' => true,
    ]);

    $token = adminLogin($this, 'keeper@example.com')['token'];
    $secret = $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol')->json('data.secret');
    $recovery = $this->withToken($token)->postJson('/api/v1/auth/mfa/verify', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->json('data.recovery_codes');

    $this->withToken($token)
        ->deleteJson('/api/v1/auth/mfa', ['code' => $recovery[0]])
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    expect(PersonalAccessToken::findToken($token))->not->toBeNull();
});

// ─── Interaction with the other boundaries ────────────────────────────────────

test('a suspended admin is refused before mandatory enrolment is even considered', function (): void {
    $this->admin->forceFill(['is_active' => false])->save();
    resetClient($this);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'mandatory@example.com',
        'password' => ADMIN_PASSWORD,
    ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('a wrong password never produces an enrolment credential', function (): void {
    resetClient($this);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'mandatory@example.com',
        'password' => 'wrong',
    ])->assertStatus(401);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('a user promoted to admin is forced to enrol on next sign-in', function (): void {
    $user = makeAccount([
        'name' => 'Promoted',
        'email' => 'promoted@example.com',
        'password' => ADMIN_PASSWORD,
        'account_type' => AccountType::USER,
        'is_active' => true,
    ]);

    expect(adminLogin($this, 'promoted@example.com')['abilities'])
        ->toBe([TokenAbility::USER_ACCESS->value]);

    $user->forceFill(['account_type' => AccountType::ADMIN])->save();

    expect(adminLogin($this, 'promoted@example.com')['mfa_setup_required'])->toBeTrue();
});
