<?php

declare(strict_types=1);

use App\Modules\Auth\Contracts\MfaManagerContract;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Models\MfaMethod;
use App\Modules\Auth\Services\Mfa\SmsOtpMethod;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

const OTP_PHONE = '+15551234567';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    // Route SMS through Twilio under Http::fake, so a test can read the message body
    // exactly as the vendor would receive it. The platform never returns a code, so
    // intercepting the outgoing message is the only honest way to learn one.
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM_test'], 201)]);

    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $twilio->setCredentials(['account_sid' => 'AC_test', 'auth_token' => 'tok_test']);
    $twilio->forceFill(['settings' => ['from' => '+15550000000'], 'is_active' => true])->save();
    IntegrationProvider::query()->where('driver', 'log')->update(['is_active' => false, 'is_default' => false]);
    IntegrationProvider::query()->where('driver', 'twilio')->update(['is_default' => true]);

    $this->mfa = app(MfaManagerContract::class);

    $this->user = makeAccount([
        'name' => 'OTP User',
        'email' => 'otp@example.com',
        'account_type' => AccountType::USER,
    ]);
});

/**
 * The six-digit code in the most recently dispatched message.
 */
function lastDeliveredCode(): string
{
    $bodies = [];

    foreach (Http::recorded() as [$request]) {
        if (isset($request['Body'])) {
            $bodies[] = (string) $request['Body'];
        }
    }

    if ($bodies === [] || preg_match('/\b(\d{6})\b/', end($bodies), $matches) !== 1) {
        return '';
    }

    return $matches[1];
}

/**
 * Enrol and confirm SMS for a user, leaving the method active.
 */
function confirmSmsFor(mixed $test, User $user): void
{
    resetClient($test);
    $token = $test->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.token');

    $test->withToken($token)->postJson('/api/v1/auth/mfa/enrol', [
        'type' => MfaType::SMS_OTP->value,
        'phone' => OTP_PHONE,
    ])->assertOk();

    $test->withToken($token)->postJson('/api/v1/auth/mfa/verify', [
        'type' => MfaType::SMS_OTP->value,
        'code' => lastDeliveredCode(),
    ])->assertOk();

    resetClient($test);
}

/**
 * Enrol and confirm TOTP for a regular user.
 */
function confirmTotpFor(mixed $test, User $user): void
{
    resetClient($test);
    $token = $test->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.token');

    $secret = $test->withToken($token)
        ->postJson('/api/v1/auth/mfa/enrol', ['type' => MfaType::TOTP->value])
        ->json('data.secret');

    $test->withToken($token)->postJson('/api/v1/auth/mfa/verify', [
        'type' => MfaType::TOTP->value,
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertOk();

    resetClient($test);
}

// ── Enrolment ─────────────────────────────────────────────────────────────────

test('enrolling SMS stores the number encrypted and returns only a masked form', function (): void {
    resetClient($this);
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.token');

    $response = $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol', [
        'type' => MfaType::SMS_OTP->value,
        'phone' => OTP_PHONE,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.type', MfaType::SMS_OTP->value)
        ->assertJsonPath('data.destination', '********4567');

    // A delivery method has neither of the shared-secret fields.
    expect($response->getContent())->not->toContain(OTP_PHONE)
        ->and($response->json('data'))->not->toHaveKey('secret')
        ->and($response->json('data'))->not->toHaveKey('uri');

    $method = MfaMethod::query()->where('user_id', $this->user->id)->firstOrFail();
    $stored = DB::table('mfa_methods')->where('id', $method->id)->value('destination');

    expect($stored)->toBeString()
        ->and($stored)->not->toContain(OTP_PHONE)
        ->and($method->getDestination())->toBe(OTP_PHONE);
});

test('enrolment dispatches the code through the Integration module', function (): void {
    confirmSmsFor($this, $this->user);

    $log = IntegrationUsageLog::query()->firstOrFail();

    expect($log->driver)->toBe('twilio')
        ->and($log->status->value)->toBe('success');

    // The code went out over the vendor's own request shape.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'Messages.json')
        && $request['To'] === OTP_PHONE);
});

test('the code never appears in any response and is stored only as a hash', function (): void {
    resetClient($this);
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.token');

    $response = $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol', [
        'type' => MfaType::SMS_OTP->value, 'phone' => OTP_PHONE,
    ]);

    $code = lastDeliveredCode();
    expect($code)->toHaveLength(6);

    // The platform sent it, but never told the client what it was.
    expect($response->getContent())->not->toContain($code);

    $hash = DB::table('mfa_methods')->where('user_id', $this->user->id)->value('otp_hash');

    expect($hash)->toBeString()
        ->and($hash)->not->toContain($code)
        ->and(Hash::check($code, $hash))->toBeTrue();
});

test('enrolment leaves the method unconfirmed until a code is answered', function (): void {
    resetClient($this);
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol', [
        'type' => MfaType::SMS_OTP->value, 'phone' => OTP_PHONE,
    ])->assertOk();

    expect(MfaMethod::query()->where('user_id', $this->user->id)->firstOrFail()->isConfirmed())->toBeFalse()
        ->and($this->mfa->isEnabled($this->user->refresh()))->toBeFalse();
});

test('SMS enrolment requires a phone number in international format', function (): void {
    resetClient($this);
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.token');

    foreach (['', '5551234567', '+0123', 'not-a-number'] as $bad) {
        $this->withToken($token)
            ->postJson('/api/v1/auth/mfa/enrol', ['type' => MfaType::SMS_OTP->value, 'phone' => $bad])
            ->assertStatus(422);
    }

    expect(MfaMethod::query()->count())->toBe(0);
});

test('a wrong code does not confirm the enrolment', function (): void {
    resetClient($this);
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol', [
        'type' => MfaType::SMS_OTP->value, 'phone' => OTP_PHONE,
    ])->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/auth/mfa/verify', ['type' => MfaType::SMS_OTP->value, 'code' => '000000'])
        ->assertStatus(422);

    expect($this->mfa->isEnabled($this->user->refresh()))->toBeFalse();
});

test('the delivered code confirms the method and issues recovery codes', function (): void {
    resetClient($this);
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol', [
        'type' => MfaType::SMS_OTP->value, 'phone' => OTP_PHONE,
    ])->assertOk();

    $response = $this->withToken($token)->postJson('/api/v1/auth/mfa/verify', [
        'type' => MfaType::SMS_OTP->value,
        'code' => lastDeliveredCode(),
    ]);

    $response->assertOk()->assertJsonPath('data.enabled', true);

    expect($response->json('data.recovery_codes'))->toHaveCount(8)
        ->and($this->mfa->hasConfirmedMethod($this->user->refresh(), MfaType::SMS_OTP))->toBeTrue();
});

// ── The challenge flow ────────────────────────────────────────────────────────

test('an SMS user is challenged at login and no code is sent yet', function (): void {
    confirmSmsFor($this, $this->user);
    IntegrationUsageLog::query()->delete();

    $data = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data');

    expect($data['mfa_required'])->toBeTrue()
        ->and($data)->not->toHaveKey('token')
        // Signing in must not be an SMS amplifier: nothing was dispatched.
        ->and(IntegrationUsageLog::query()->count())->toBe(0);
});

test('requesting delivery sends a code and reports only the masked destination', function (): void {
    confirmSmsFor($this, $this->user);
    IntegrationUsageLog::query()->delete();

    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.mfa_token');

    $this->travel(31)->seconds();
    $response = $this->postJson('/api/v1/auth/mfa/challenge/send', ['mfa_token' => $mfaToken]);

    $response->assertOk()->assertJsonPath('data.destination', '********4567');

    expect($response->getContent())->not->toContain(OTP_PHONE)
        ->and($response->getContent())->not->toContain(lastDeliveredCode())
        ->and(IntegrationUsageLog::query()->count())->toBe(1);
});

test('a delivered challenge code completes sign-in', function (): void {
    confirmSmsFor($this, $this->user);

    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.mfa_token');

    $this->travel(31)->seconds();
    $this->postJson('/api/v1/auth/mfa/challenge/send', ['mfa_token' => $mfaToken])->assertOk();

    $response = $this->postJson('/api/v1/auth/mfa/challenge', [
        'mfa_token' => $mfaToken,
        'code' => lastDeliveredCode(),
    ]);

    $response->assertOk()->assertJsonPath('data.abilities', [TokenAbility::USER_ACCESS->value]);

    expect(PersonalAccessToken::findToken($response->json('data.token')))->not->toBeNull();
});

test('a delivered code is single use', function (): void {
    confirmSmsFor($this, $this->user);
    $method = MfaMethod::query()->where('user_id', $this->user->id)->firstOrFail();

    $this->travel(31)->seconds();
    app(SmsOtpMethod::class)->deliver($this->user, $method);
    $code = lastDeliveredCode();

    expect($this->mfa->verifyChallenge($this->user, $code))->toBeTrue()
        // The same code again is worthless, though its lifetime has not run out.
        ->and($this->mfa->verifyChallenge($this->user->refresh(), $code))->toBeFalse();
});

test('an expired code is refused', function (): void {
    confirmSmsFor($this, $this->user);
    $method = MfaMethod::query()->where('user_id', $this->user->id)->firstOrFail();

    $this->travel(31)->seconds();
    app(SmsOtpMethod::class)->deliver($this->user, $method);
    $code = lastDeliveredCode();

    $this->travel(301)->seconds();

    expect($this->mfa->verifyChallenge($this->user->refresh(), $code))->toBeFalse();
});

test('a resend invalidates the previous code', function (): void {
    confirmSmsFor($this, $this->user);
    $method = MfaMethod::query()->where('user_id', $this->user->id)->firstOrFail();

    $this->travel(31)->seconds();
    app(SmsOtpMethod::class)->deliver($this->user, $method);
    $first = lastDeliveredCode();

    $this->travel(31)->seconds();
    app(SmsOtpMethod::class)->deliver($this->user, $method->refresh());
    $second = lastDeliveredCode();

    expect($second)->not->toBe($first)
        ->and($this->mfa->verifyChallenge($this->user, $first))->toBeFalse()
        ->and($this->mfa->verifyChallenge($this->user->refresh(), $second))->toBeTrue();
});

test('delivery is refused inside the resend cooldown', function (): void {
    confirmSmsFor($this, $this->user);

    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.mfa_token');

    $this->travel(31)->seconds();
    $this->postJson('/api/v1/auth/mfa/challenge/send', ['mfa_token' => $mfaToken])->assertOk();

    // Immediately again: the endpoint must not become an SMS amplifier.
    $this->postJson('/api/v1/auth/mfa/challenge/send', ['mfa_token' => $mfaToken])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'MFA_DELIVERY_THROTTLED');
});

test('the send endpoint is throttled on top of the cooldown', function (): void {
    // Each send has to clear the 30 second cooldown, so the throttle window is widened
    // to outlast the travel; otherwise the counter expires between sends and the limit
    // is never reached, which would make this test pass for the wrong reason.
    app(SettingServiceInterface::class)
        ->updateGroup('security', ['decay_minutes' => 30]);

    confirmSmsFor($this, $this->user);

    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.mfa_token');

    $max = setting('security.max_login_attempts');

    for ($i = 0; $i < $max; $i++) {
        $this->travel(31)->seconds();
        $this->postJson('/api/v1/auth/mfa/challenge/send', ['mfa_token' => $mfaToken]);
    }

    $this->travel(31)->seconds();
    $this->postJson('/api/v1/auth/mfa/challenge/send', ['mfa_token' => $mfaToken])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
});

test('requesting delivery for a TOTP account reports that none is needed', function (): void {
    $totpUser = makeAccount(['email' => 'totp-only@example.com', 'account_type' => AccountType::USER]);
    confirmTotpFor($this, $totpUser);

    $mfaToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'totp-only@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.mfa_token');

    $this->postJson('/api/v1/auth/mfa/challenge/send', ['mfa_token' => $mfaToken])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'MFA_DELIVERY_NOT_APPLICABLE');
});

test('an invalid challenge token yields no delivery', function (): void {
    confirmSmsFor($this, $this->user);
    IntegrationUsageLog::query()->delete();

    $this->postJson('/api/v1/auth/mfa/challenge/send', ['mfa_token' => str_repeat('a', 64)])
        ->assertStatus(401);

    expect(IntegrationUsageLog::query()->count())->toBe(0);
});

// ── The administrator policy ──────────────────────────────────────────────────

test('an administrator cannot enrol SMS as their second factor', function (): void {
    $admin = makeAccount([
        'email' => 'sms-admin@example.com',
        'account_type' => AccountType::ADMIN,
    ]);

    resetClient($this);
    $enrolmentToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'sms-admin@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.enrolment_token');

    $this->withToken($enrolmentToken)
        ->postJson('/api/v1/auth/mfa/enrol', [
            'type' => MfaType::SMS_OTP->value,
            'phone' => OTP_PHONE,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'MFA_ENROLMENT_INVALID');

    expect(MfaMethod::query()->where('user_id', $admin->id)->count())->toBe(0);
});

test('an administrator with only SMS confirmed still does not satisfy the policy', function (): void {
    // Force the state the API refuses to create, and prove the policy still holds.
    $account = makeAccount(['email' => 'forced-sms@example.com', 'account_type' => AccountType::USER]);
    confirmSmsFor($this, $account);

    $account->forceFill(['account_type' => AccountType::ADMIN])->save();

    expect($this->mfa->isEnabled($account->refresh()))->toBeTrue()
        // Any confirmed method counts as enabled, but only TOTP satisfies the policy.
        ->and($this->mfa->satisfiesPolicy($account))->toBeFalse();

    resetClient($this);
    $data = $this->postJson('/api/v1/auth/login', [
        'email' => 'forced-sms@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data');

    // Sent to enrolment rather than handed an admin token.
    expect($data['mfa_setup_required'])->toBeTrue()
        ->and($data['abilities'])->toBe([TokenAbility::MFA_ENROL->value])
        ->and($data)->not->toHaveKey('token');
});

test('the status endpoint offers an administrator only policy-satisfying methods', function (): void {
    makeAccount(['email' => 'methods-admin@example.com', 'account_type' => AccountType::ADMIN]);
    $result = signInAdminWithMfa($this, 'methods-admin@example.com', TEST_ACCOUNT_PASSWORD);

    $data = $this->withToken($result['token'])->getJson('/api/v1/auth/mfa')->assertOk()->json('data');

    expect($data['available_methods'])->toBe([MfaType::TOTP->value])
        ->and($data['satisfies_policy'])->toBeTrue();
});

test('a regular user is offered both methods', function (): void {
    resetClient($this);
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'otp@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.token');

    $data = $this->withToken($token)->getJson('/api/v1/auth/mfa')->assertOk()->json('data');

    expect($data['available_methods'])->toBe([MfaType::TOTP->value, MfaType::SMS_OTP->value]);
});

// ── Database invariant ────────────────────────────────────────────────────────

test('the database accepts both method types and rejects any other', function (): void {
    foreach (MfaType::values() as $type) {
        DB::table('mfa_methods')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $this->user->id,
            'type' => $type,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(DB::table('mfa_methods')->count())->toBe(count(MfaType::values()));

    $rejected = false;
    try {
        DB::transaction(fn () => DB::table('mfa_methods')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $this->user->id,
            'type' => 'carrier_pigeon',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    } catch (QueryException) {
        $rejected = true;
    }

    expect($rejected)->toBeTrue();
});
