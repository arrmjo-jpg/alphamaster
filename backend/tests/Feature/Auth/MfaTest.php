<?php

declare(strict_types=1);

use App\Modules\Auth\Contracts\MfaManagerContract;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Models\MfaMethod;
use App\Modules\Auth\Models\MfaRecoveryCode;
use App\Modules\Auth\Services\MfaManager;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

const MFA_PASSWORD = 'mfa-user-password';

beforeEach(function (): void {
    // The container runs against Redis, where rate-limiter counters outlive a test.
    Cache::flush();

    $this->seed(SettingSeeder::class);

    $this->user = User::create([
        'name' => 'MFA User',
        'email' => 'mfa@example.com',
        'password' => MFA_PASSWORD,
        'is_admin' => false,
        'is_active' => true,
    ]);

    $this->google2fa = app(Google2FA::class);
});

/**
 * Drive the full enrolment: enrol, read the secret, confirm with a real code.
 *
 * @return array{token: string, secret: string, recovery: array<int, string>}
 */
function enrolMfa(mixed $test): array
{
    $token = $test->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com',
        'password' => MFA_PASSWORD,
    ])->json('data.token');

    $secret = $test->withToken($token)
        ->postJson('/api/v1/auth/mfa/enrol')
        ->assertOk()
        ->json('data.secret');

    $recovery = $test->withToken($token)
        ->postJson('/api/v1/auth/mfa/verify', [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])
        ->assertOk()
        ->json('data.recovery_codes');

    return ['token' => $token, 'secret' => $secret, 'recovery' => $recovery];
}

/**
 * A TOTP code for a specific time slice.
 *
 * Time-travel helpers cannot be used here: Google2FA reads microtime() directly, so
 * Carbon's fake clock does not move it. Asking for a future slice is the only way to
 * obtain a code that replay protection will accept as newer than the last one used.
 */
function otpAt(string $secret, int $sliceOffset = 0): string
{
    $google2fa = app(Google2FA::class);

    return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $sliceOffset);
}
// ─── Enrolment ────────────────────────────────────────────────────────────────

test('enrolment returns a scannable secret and does not activate MFA yet', function (): void {
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.token');

    $response = $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol');

    $response->assertOk()
        ->assertJsonPath('data.type', 'totp');

    expect($response->json('data.secret'))->toBeString()
        ->and($response->json('data.uri'))->toStartWith('otpauth://totp/');

    // Unconfirmed: sign-in must not start demanding a code yet.
    expect(app(MfaManagerContract::class)->isEnabled($this->user->fresh()))->toBeFalse();
});

test('a wrong code does not confirm the enrolment', function (): void {
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol')->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'MFA_ENROLMENT_INVALID');

    expect(app(MfaManagerContract::class)->isEnabled($this->user->fresh()))->toBeFalse();
});

test('confirming with a genuine TOTP code enables MFA and issues recovery codes', function (): void {
    $result = enrolMfa($this);

    expect($result['recovery'])->toHaveCount(MfaManager::RECOVERY_CODE_COUNT)
        ->and(app(MfaManagerContract::class)->isEnabled($this->user->fresh()))->toBeTrue();

    // Only hashes are persisted.
    $stored = MfaRecoveryCode::query()->where('user_id', $this->user->id)->pluck('code_hash');
    expect($stored)->toHaveCount(MfaManager::RECOVERY_CODE_COUNT);
    foreach ($result['recovery'] as $plain) {
        expect($stored->contains($plain))->toBeFalse();
    }
});

// ─── The challenge cannot be bypassed ─────────────────────────────────────────

test('once MFA is enabled, login returns a challenge instead of an access token', function (): void {
    enrolMfa($this);
    resetClient($this);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.mfa_required', true);

    expect($response->json('data.mfa_token'))->toBeString()
        // No access token whatsoever in the challenge response.
        ->and($response->json('data.token'))->toBeNull();
});

test('the mfa_token is not itself an access token', function (): void {
    enrolMfa($this);
    resetClient($this);

    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.mfa_token');

    // Presenting the challenge token as a bearer credential must grant nothing.
    expect(PersonalAccessToken::findToken($mfaToken))->toBeNull();

    $this->withToken($mfaToken)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('the challenge cannot be cleared with a wrong code', function (): void {
    enrolMfa($this);
    resetClient($this);

    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.mfa_token');

    $this->postJson('/api/v1/auth/mfa/challenge', [
        'mfa_token' => $mfaToken,
        'code' => '000000',
    ])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'MFA_CHALLENGE_FAILED');

    expect(PersonalAccessToken::query()->count())->toBe(1); // only the enrolment token
});

test('a forged or expired mfa_token is refused', function (): void {
    enrolMfa($this);
    resetClient($this);

    $this->postJson('/api/v1/auth/mfa/challenge', [
        'mfa_token' => str_repeat('a', 64),
        'code' => '000000',
    ])->assertStatus(401);
});

test('a valid TOTP code clears the challenge and issues the real token', function (): void {
    $result = enrolMfa($this);
    resetClient($this);

    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.mfa_token');

    // Enrolment consumed the current slice, so a newer one is required.
    $response = $this->postJson('/api/v1/auth/mfa/challenge', [
        'mfa_token' => $mfaToken,
        'code' => otpAt($result['secret'], 1),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.abilities', [TokenAbility::USER_ACCESS->value]);

    expect(PersonalAccessToken::findToken($response->json('data.token')))->not->toBeNull();
});

test('an mfa_token is single use and cannot be replayed', function (): void {
    $result = enrolMfa($this);
    resetClient($this);

    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.mfa_token');

    $this->postJson('/api/v1/auth/mfa/challenge', [
        'mfa_token' => $mfaToken,
        'code' => otpAt($result['secret'], 1),
    ])->assertOk();

    // The same challenge token again, this time with an unused recovery code. The
    // code is unquestionably valid, so a refusal can only be the spent mfa_token.
    $this->postJson('/api/v1/auth/mfa/challenge', [
        'mfa_token' => $mfaToken,
        'code' => $result['recovery'][0],
    ])->assertStatus(401);
});

test('a TOTP code cannot be replayed within its own time window', function (): void {
    $result = enrolMfa($this);
    resetClient($this);

    // One code, still inside its own validity window for both attempts.
    $code = otpAt($result['secret'], 1);

    $first = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.mfa_token');

    $this->postJson('/api/v1/auth/mfa/challenge', ['mfa_token' => $first, 'code' => $code])
        ->assertOk();

    // A second challenge, same still-valid code: must be refused as already used.
    $second = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.mfa_token');

    $this->postJson('/api/v1/auth/mfa/challenge', ['mfa_token' => $second, 'code' => $code])
        ->assertStatus(401);
});

test('the MFA challenge is throttled', function (): void {
    enrolMfa($this);
    resetClient($this);

    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.mfa_token');

    $max = setting('security.max_login_attempts');

    for ($i = 0; $i < $max; $i++) {
        $this->postJson('/api/v1/auth/mfa/challenge', [
            'mfa_token' => $mfaToken, 'code' => '000000',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/mfa/challenge', [
        'mfa_token' => $mfaToken, 'code' => '000000',
    ])->assertStatus(429);
});

// ─── Recovery codes ───────────────────────────────────────────────────────────

test('a recovery code clears the challenge', function (): void {
    $result = enrolMfa($this);
    resetClient($this);

    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.mfa_token');

    $this->postJson('/api/v1/auth/mfa/challenge', [
        'mfa_token' => $mfaToken,
        'code' => $result['recovery'][0],
    ])->assertOk();
});

test('a recovery code is consumed exactly once', function (): void {
    $result = enrolMfa($this);
    resetClient($this);
    $code = $result['recovery'][0];

    $first = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.mfa_token');

    $this->postJson('/api/v1/auth/mfa/challenge', ['mfa_token' => $first, 'code' => $code])
        ->assertOk();

    // The very same code, on a fresh challenge, must now be worthless.
    $second = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.mfa_token');

    $this->postJson('/api/v1/auth/mfa/challenge', ['mfa_token' => $second, 'code' => $code])
        ->assertStatus(401);

    expect(MfaRecoveryCode::query()->where('user_id', $this->user->id)->unused()->count())
        ->toBe(MfaManager::RECOVERY_CODE_COUNT - 1);
});

test('each recovery code is independent of the others', function (): void {
    $result = enrolMfa($this);
    resetClient($this);
    $manager = app(MfaManagerContract::class);

    expect($manager->verifyChallenge($this->user, $result['recovery'][0]))->toBeTrue()
        ->and($manager->verifyChallenge($this->user, $result['recovery'][1]))->toBeTrue()
        ->and($manager->verifyChallenge($this->user, $result['recovery'][0]))->toBeFalse();
});

// ─── Disable ──────────────────────────────────────────────────────────────────

test('disabling requires a valid code and then removes every trace', function (): void {
    $result = enrolMfa($this);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ]);
    // Signing in now needs the challenge, so reuse the enrolment session token.
    $sessionToken = $result['token'];

    $this->withToken($sessionToken)
        ->deleteJson('/api/v1/auth/mfa', ['code' => '000000'])
        ->assertStatus(401);

    expect(app(MfaManagerContract::class)->isEnabled($this->user->fresh()))->toBeTrue();

    $this->withToken($sessionToken)
        ->deleteJson('/api/v1/auth/mfa', ['code' => $result['recovery'][2]])
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    expect(MfaMethod::query()->where('user_id', $this->user->id)->count())->toBe(0)
        ->and(MfaRecoveryCode::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

test('after disabling, sign-in no longer demands a challenge', function (): void {
    $result = enrolMfa($this);

    $this->withToken($result['token'])
        ->deleteJson('/api/v1/auth/mfa', ['code' => $result['recovery'][0]])
        ->assertOk();

    resetClient($this);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])
        ->assertOk()
        ->assertJsonPath('data.abilities', [TokenAbility::USER_ACCESS->value]);
});

// ─── Secret handling ──────────────────────────────────────────────────────────

test('the TOTP secret is encrypted at rest and never returned again', function (): void {
    $result = enrolMfa($this);

    $stored = DB::table('mfa_methods')->where('user_id', $this->user->id)->value('secret');

    expect($stored)->not->toBe($result['secret'])
        ->and($stored)->not->toContain($result['secret'])
        ->and(MfaMethod::query()->where('user_id', $this->user->id)->first()->getSecret())
        ->toBe($result['secret']);

    // The status endpoint must never hand the secret back out.
    $status = $this->withToken($result['token'])->getJson('/api/v1/auth/mfa');

    $status->assertOk()->assertJsonPath('data.enabled', true);
    expect($status->getContent())->not->toContain($result['secret']);
});

test('the MFA secret and recovery codes never reach the cache store', function (): void {
    $result = enrolMfa($this);
    resetClient($this);

    // Opening a challenge is what writes to the cache.
    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com', 'password' => MFA_PASSWORD,
    ])->json('data.mfa_token');

    $store = Cache::getStore();
    $dump = method_exists($store, 'all') ? json_encode($store->all()) : '';

    // The challenge entry is keyed by a hash of the token and holds only a user id,
    // so neither the secret, the recovery codes, nor the token itself is recoverable.
    expect($dump)->not->toContain($result['secret'])
        ->and($dump)->not->toContain($mfaToken);

    foreach ($result['recovery'] as $code) {
        expect($dump)->not->toContain($code);
    }
});

test('the model hides the secret and the code hash from serialisation', function (): void {
    enrolMfa($this);

    $method = MfaMethod::query()->where('user_id', $this->user->id)->first();
    $recovery = MfaRecoveryCode::query()->where('user_id', $this->user->id)->first();

    expect($method->toArray())->not->toHaveKey('secret')
        ->and($recovery->toArray())->not->toHaveKey('code_hash')
        ->and(json_encode($method))->not->toContain($method->getSecret());
});
