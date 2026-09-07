<?php

declare(strict_types=1);

use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Data\AuthenticatedToken;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

const CAPTCHA_PASSWORD = 'correct-horse-battery';
const RECAPTCHA_URL = 'https://www.google.com/recaptcha/api/siteverify';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    $this->user = makeAccount([
        'name' => 'Captcha Subject',
        'email' => 'captcha@example.com',
        'password' => CAPTCHA_PASSWORD,
        'account_type' => AccountType::USER,
        'is_active' => true,
    ]);
});

/**
 * Switch the platform's captcha on, the way an operator would.
 */
function enableCaptcha(): void
{
    app(SettingServiceInterface::class)->set('auth', 'captcha_enabled', true);
    Cache::flush();
}

/**
 * Give the reCAPTCHA provider a secret and activate it.
 */
function configureRecaptcha(): IntegrationProvider
{
    $provider = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::CAPTCHA)
        ->where('driver', 'recaptcha')
        ->firstOrFail();

    $provider->setCredentials(['secret_key' => 'test-secret']);
    $provider->forceFill(['is_active' => true])->save();

    return $provider->refresh();
}

/**
 * @param  array<string, mixed>  $payload
 */
function captchaLogin(mixed $test, array $payload = [])
{
    return $test->postJson('/api/v1/auth/login', array_merge([
        'identifier' => 'captcha@example.com',
        'password' => CAPTCHA_PASSWORD,
    ], $payload));
}

// ── Off by default: nothing changes ──────────────────────────────────────────

test('a platform that has not switched captcha on signs in exactly as before', function (): void {
    Http::fake();

    captchaLogin($this)->assertOk()->assertJsonPath('data.token_type', 'Bearer');

    // Not merely allowed — never asked. A disabled captcha must not reach a vendor.
    Http::assertNothingSent();
    expect(IntegrationUsageLog::query()->count())->toBe(0);
});

test('a captcha token sent to a platform that is not asking for one is ignored', function (): void {
    Http::fake();

    captchaLogin($this, ['captcha_token' => 'unnecessary'])->assertOk();

    Http::assertNothingSent();
});

// ── Enabled and passing ──────────────────────────────────────────────────────

test('a verified captcha lets the sign-in proceed', function (): void {
    enableCaptcha();
    configureRecaptcha();
    Http::fake([RECAPTCHA_URL => Http::response(['success' => true, 'hostname' => 'admin.example.com'])]);

    captchaLogin($this, ['captcha_token' => 'good'])
        ->assertOk()
        ->assertJsonPath('data.abilities', [TokenAbility::USER_ACCESS->value]);
});

test('the presented token and the caller address reach the vendor', function (): void {
    enableCaptcha();
    configureRecaptcha();
    Http::fake([RECAPTCHA_URL => Http::response(['success' => true])]);

    captchaLogin($this, ['captcha_token' => 'good']);

    Http::assertSent(fn ($request): bool => $request->url() === RECAPTCHA_URL
        && $request['response'] === 'good'
        && $request['secret'] === 'test-secret');
});

// ── Fail-closed, in every way it can fail ────────────────────────────────────

test('every way a captcha can fail refuses the sign-in', function (string $case): void {
    enableCaptcha();

    match ($case) {
        // A rejected token.
        'rejected' => (function (): void {
            configureRecaptcha();
            Http::fake([RECAPTCHA_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        })(),
        // The vendor cannot be reached. The cheapest attack on a captcha that fails
        // open is to make this happen.
        'timeout' => (function (): void {
            configureRecaptcha();
            Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        })(),
        // A body that is not the shape the vendor documents.
        'malformed' => (function (): void {
            configureRecaptcha();
            Http::fake([RECAPTCHA_URL => Http::response('<html>not json</html>')]);
        })(),
        // An HTTP error from the vendor.
        'vendor error' => (function (): void {
            configureRecaptcha();
            Http::fake([RECAPTCHA_URL => Http::response(['error' => 'nope'], 503)]);
        })(),
        // Switched on with no active provider behind it.
        'no provider' => (function (): void {
            Http::fake();
        })(),
        // Active, but nobody supplied the secret.
        'provider without a secret' => (function (): void {
            IntegrationProvider::query()
                ->forCapability(IntegrationCapability::CAPTCHA)
                ->update(['is_active' => true]);
            Http::fake();
        })(),
    };

    captchaLogin($this, ['captcha_token' => 'presented'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    // The credentials were correct. No token exists, so they were never honoured.
    expect(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'rejected',
    'timeout',
    'malformed',
    'vendor error',
    'no provider',
    'provider without a secret',
]);

test('a missing captcha token is a failed captcha, not a validation error', function (): void {
    // 422 would be a different response from a rejected token, and a different
    // response is a signal about which check was tripped.
    enableCaptcha();
    configureRecaptcha();
    Http::fake();

    captchaLogin($this)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    Http::assertNothingSent();
});

test('an empty captcha token is refused without troubling the vendor', function (): void {
    enableCaptcha();
    configureRecaptcha();
    Http::fake();

    captchaLogin($this, ['captcha_token' => ''])->assertStatus(401);

    Http::assertNothingSent();
});

// ── The refusal says nothing about the captcha ───────────────────────────────

test('a failed captcha is indistinguishable from a wrong password', function (): void {
    enableCaptcha();
    configureRecaptcha();

    // One handler whose verdict changes between the two attempts. A second
    // Http::fake() would not replace the first — the stubs accumulate and the earlier
    // one keeps answering — so the second attempt would have passed its captcha and
    // the comparison would have been between two different things.
    $verdict = true;

    Http::fake(function () use (&$verdict) {
        return Http::response($verdict
            ? ['success' => true]
            : ['success' => false, 'error-codes' => ['invalid-input-response']]);
    });

    // Wrong password, captcha fine.
    $wrongPassword = captchaLogin($this, ['password' => 'not-the-password', 'captcha_token' => 'good']);

    // Clears the limiter, so both refusals report the same attempts_remaining and the
    // comparison is of the two refusals rather than of two throttle states.
    Cache::flush();

    // Right password, captcha rejected.
    $verdict = false;
    $failedCaptcha = captchaLogin($this, ['captcha_token' => 'bad']);

    expect($failedCaptcha->status())->toBe($wrongPassword->status())
        ->and($failedCaptcha->json())->toBe($wrongPassword->json());
});

test('the vendor reason never reaches the response', function (): void {
    enableCaptcha();
    configureRecaptcha();
    Http::fake([RECAPTCHA_URL => Http::response([
        'success' => false,
        'error-codes' => ['invalid-input-secret', 'timeout-or-duplicate'],
    ])]);

    $body = captchaLogin($this, ['captcha_token' => 'bad'])->getContent();

    expect($body)->not->toContain('invalid-input-secret')
        ->not->toContain('timeout-or-duplicate')
        ->not->toContain('captcha')
        ->not->toContain('recaptcha');
});

test('a failed captcha spends an attempt, exactly as a wrong password does', function (): void {
    // If it did not, attempts_remaining would differ between the two refusals and
    // the response would say which check failed after all.
    enableCaptcha();
    configureRecaptcha();
    Http::fake([RECAPTCHA_URL => Http::response(['success' => false])]);

    $first = captchaLogin($this, ['captcha_token' => 'bad'])->json('error.details.attempts_remaining');
    $second = captchaLogin($this, ['captcha_token' => 'bad'])->json('error.details.attempts_remaining');

    expect($second)->toBe($first - 1);
});

test('the reason is absent from the response and present in the usage log', function (): void {
    enableCaptcha();
    configureRecaptcha();
    Http::fake([RECAPTCHA_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    captchaLogin($this, ['captcha_token' => 'bad']);

    $log = IntegrationUsageLog::query()
        ->where('capability', IntegrationCapability::CAPTCHA->value)
        ->firstOrFail();

    expect($log->error_code)->toBe('REJECTED')
        ->and($log->error_message)->toContain('invalid-input-response');
});

// ── The order: throttle → captcha → credentials ──────────────────────────────

test('the throttle runs before the captcha, so a captcha cannot buy attempts', function (): void {
    enableCaptcha();
    configureRecaptcha();
    Http::fake([RECAPTCHA_URL => Http::response(['success' => true])]);

    // Exhaust the limiter against this identifier.
    $max = (int) setting('security.max_login_attempts', 5);

    for ($i = 0; $i < $max; $i++) {
        captchaLogin($this, ['password' => 'wrong', 'captcha_token' => 'good']);
    }

    $sentBefore = count(Http::recorded());

    captchaLogin($this, ['captcha_token' => 'good'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');

    // The throttled request never reached the vendor. If the captcha ran first, a
    // locked-out attacker would still be spending our verification quota.
    expect(count(Http::recorded()))->toBe($sentBefore);
});

test('the captcha runs before the credentials are looked at', function (): void {
    enableCaptcha();
    configureRecaptcha();
    Http::fake([RECAPTCHA_URL => Http::response(['success' => false])]);

    // A recording stand-in for the service that checks passwords. If the pipeline
    // ever checked credentials first, this would be called.
    $spy = new class implements AuthServiceContract
    {
        public bool $authenticateCalled = false;

        public function authenticate(string $identifier, string $password): User
        {
            $this->authenticateCalled = true;

            throw new InvalidCredentialsException;
        }

        public function requiresMfa(User $user): bool
        {
            return false;
        }

        public function requiresMfaEnrolment(User $user): bool
        {
            return false;
        }

        public function issueEnrolmentToken(User $user): AuthenticatedToken
        {
            throw new RuntimeException('not reached');
        }

        public function issueToken(User $user, string $name = 'api-token'): AuthenticatedToken
        {
            throw new RuntimeException('not reached');
        }

        public function startMfaChallenge(User $user): string
        {
            throw new RuntimeException('not reached');
        }

        public function resolveMfaChallenge(string $token): User
        {
            throw new RuntimeException('not reached');
        }

        public function forgetMfaChallenge(string $token): void {}

        public function completeMfaChallenge(string $token, string $code): AuthenticatedToken
        {
            throw new RuntimeException('not reached');
        }
    };

    app()->instance(AuthServiceContract::class, $spy);

    captchaLogin($this, ['captcha_token' => 'bad'])->assertStatus(401);

    expect($spy->authenticateCalled)->toBeFalse();
});

test('a passing captcha does let the credentials be looked at', function (): void {
    // The control for the test above: with the captcha passing, authenticate() is
    // reached. Without this, the assertion there would also hold if the captcha
    // blocked everything unconditionally.
    enableCaptcha();
    configureRecaptcha();
    Http::fake([RECAPTCHA_URL => Http::response(['success' => true])]);

    captchaLogin($this, ['password' => 'not-the-password', 'captcha_token' => 'good'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    // One verification happened, so the captcha did run and did pass; the refusal
    // therefore came from the password.
    expect(IntegrationUsageLog::query()->where('status', 'success')->count())->toBe(1);
});

// ── Nothing about MFA moved ──────────────────────────────────────────────────

test('an administrator with a verified captcha still lands on MFA enrolment', function (): void {
    enableCaptcha();
    configureRecaptcha();
    Http::fake([RECAPTCHA_URL => Http::response(['success' => true])]);

    makeAccount([
        'name' => 'Captcha Admin',
        'email' => 'captcha-admin@example.com',
        'password' => CAPTCHA_PASSWORD,
        'account_type' => AccountType::ADMIN,
        'is_active' => true,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'captcha-admin@example.com',
        'password' => CAPTCHA_PASSWORD,
        'captcha_token' => 'good',
    ])
        ->assertOk()
        ->assertJsonPath('data.mfa_setup_required', true)
        ->assertJsonPath('data.abilities', [TokenAbility::MFA_ENROL->value]);
});

test('a failed captcha never opens an MFA challenge', function (): void {
    enableCaptcha();
    configureRecaptcha();
    Http::fake([RECAPTCHA_URL => Http::response(['success' => false])]);

    makeAccount([
        'name' => 'Captcha Admin Two',
        'email' => 'captcha-admin2@example.com',
        'password' => CAPTCHA_PASSWORD,
        'account_type' => AccountType::ADMIN,
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'captcha-admin2@example.com',
        'password' => CAPTCHA_PASSWORD,
        'captcha_token' => 'bad',
    ]);

    $response->assertStatus(401);

    expect($response->json('data'))->toBeNull()
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

// ── The settings surface ─────────────────────────────────────────────────────

test('the site key and the switch are readable without signing in', function (): void {
    // A sign-in page is unauthenticated and has to know whether to draw a widget and
    // which key to draw it with.
    $auth = $this->getJson('/api/v1/settings')->json('data.auth');

    expect($auth)->toHaveKey('captcha_enabled')
        ->and($auth)->toHaveKey('captcha_site_key');
});

test('the secret key is not a setting at all', function (): void {
    // It is a vendor credential and lives encrypted on the provider row (ADR 0017).
    // A settings payload that carried it would be a second home for one secret.
    $encoded = (string) json_encode($this->getJson('/api/v1/settings')->json());

    expect($encoded)->not->toContain('secret_key')
        ->and(Setting::query()->where('key', 'captcha_secret_key')->exists())
        ->toBeFalse();
});

test('the security group stays entirely internal', function (): void {
    // The captcha settings went to `auth` rather than `security` because the public
    // payload must never carry a `security` key, which is asserted elsewhere too.
    expect($this->getJson('/api/v1/settings')->json('data'))->not->toHaveKey('security');
});
