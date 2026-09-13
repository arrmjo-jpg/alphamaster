<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Models\MfaMethod;
use App\Modules\Auth\Models\PhoneSignInCode;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * Signing in, and registering, with a phone number and a one-time code (ADR 0051 §1).
 *
 * Messages go out through Twilio under Http::fake, so a test reads a code exactly as the
 * handset would receive it: the platform stores only a hash and never returns one.
 */

uses(RefreshDatabase::class);

const PHONE_CODE = '/api/v1/auth/phone/code';
const PHONE_SIGN_IN = '/api/v1/auth/phone/sign-in';

beforeEach(function (): void {
    Cache::flush();
    config(['queue.default' => 'sync']);

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM_test'], 201)]);

    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $twilio->setCredentials(['account_sid' => 'AC_test', 'auth_token' => 'tok_test']);
    $twilio->forceFill(['settings' => ['from' => '+15550000000'], 'is_active' => true])->save();
    IntegrationProvider::query()->where('driver', 'log')->update(['is_active' => false, 'is_default' => false]);
    IntegrationProvider::query()->where('driver', 'twilio')->update(['is_default' => true]);

    app(SettingServiceInterface::class)->set('auth', 'phone_sign_in_enabled', true);
    Cache::flush();
});

/**
 * Every SMS body sent in this test, oldest first.
 *
 * @return list<string>
 */
function phoneSignInMessages(): array
{
    $bodies = [];

    foreach (Http::recorded() as [$request]) {
        if (isset($request['Body'])) {
            $bodies[] = (string) $request['Body'];
        }
    }

    return $bodies;
}

function lastPhoneSignInCode(): string
{
    $bodies = phoneSignInMessages();

    return $bodies !== [] && preg_match('/\b(\d{4,8})\b/', end($bodies), $matches) === 1 ? $matches[1] : '';
}

function phoneUser(string $number, array $attributes = []): User
{
    $user = makeAccount(array_merge(['email' => 'phone'.uniqid().'@example.test'], $attributes));
    $user->phone = $number;
    $user->save();

    return $user->refresh();
}

// ── The switch ───────────────────────────────────────────────────────────────

test('nothing is sent while phone sign-in is off', function (): void {
    app(SettingServiceInterface::class)->set('auth', 'phone_sign_in_enabled', false);
    Cache::flush();

    $this->postJson(PHONE_CODE, ['phone' => '+962790000111'])
        ->assertStatus(404)->assertJsonPath('error.code', 'PHONE_SIGN_IN_UNAVAILABLE');

    expect(phoneSignInMessages())->toBe([]);
});

// ── Signing in ───────────────────────────────────────────────────────────────

test('an existing user signs in with the code and gets an ordinary user token', function (): void {
    $user = phoneUser('+962790000111');

    $this->postJson(PHONE_CODE, ['phone' => '+962 79 000 0111'])->assertOk();

    $response = $this->postJson(PHONE_SIGN_IN, ['phone' => '+962790000111', 'code' => lastPhoneSignInCode()]);

    $response->assertOk()->assertJsonPath('data.abilities', ['user:access']);

    $token = PersonalAccessToken::findToken((string) $response->json('data.token'));

    expect($token?->tokenable_id)->toBe($user->id)
        ->and($user->fresh()->phone_verified_at)->not->toBeNull()
        ->and(PhoneSignInCode::query()->count())->toBe(0);
});

test('an account with a second factor is challenged rather than handed a token', function (): void {
    $user = phoneUser('+962790000112');
    MfaMethod::query()->create([
        'user_id' => $user->id,
        'type' => MfaType::TOTP,
        'secret' => 'ABCDEFGHIJKLMNOP',
        'confirmed_at' => now(),
    ]);

    $this->postJson(PHONE_CODE, ['phone' => '+962790000112'])->assertOk();

    $this->postJson(PHONE_SIGN_IN, ['phone' => '+962790000112', 'code' => lastPhoneSignInCode()])
        ->assertOk()
        ->assertJsonPath('data.mfa_required', true)
        ->assertJsonMissingPath('data.token');
});

test('a code is spent once', function (): void {
    phoneUser('+962790000113');
    $this->postJson(PHONE_CODE, ['phone' => '+962790000113'])->assertOk();
    $code = lastPhoneSignInCode();

    $this->postJson(PHONE_SIGN_IN, ['phone' => '+962790000113', 'code' => $code])->assertOk();

    resetClient($this);

    $this->postJson(PHONE_SIGN_IN, ['phone' => '+962790000113', 'code' => $code])
        ->assertStatus(422)->assertJsonPath('error.code', 'PHONE_SIGN_IN_INVALID_CODE');
});

test('wrong answers use up the code', function (): void {
    phoneUser('+962790000114');
    $this->postJson(PHONE_CODE, ['phone' => '+962790000114'])->assertOk();
    $code = lastPhoneSignInCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    for ($i = 0; $i < 4; $i++) {
        $this->postJson(PHONE_SIGN_IN, ['phone' => '+962790000114', 'code' => $wrong])->assertStatus(422);
    }

    Cache::flush(); // the throttle is not what is under test here

    $this->postJson(PHONE_SIGN_IN, ['phone' => '+962790000114', 'code' => $wrong])->assertStatus(422);
    $this->postJson(PHONE_SIGN_IN, ['phone' => '+962790000114', 'code' => $code])
        ->assertStatus(422)->assertJsonPath('error.code', 'PHONE_SIGN_IN_INVALID_CODE');
});

test('an expired code signs nobody in', function (): void {
    phoneUser('+962790000115');
    $this->postJson(PHONE_CODE, ['phone' => '+962790000115'])->assertOk();
    $code = lastPhoneSignInCode();

    $this->travel(301)->seconds();

    $this->postJson(PHONE_SIGN_IN, ['phone' => '+962790000115', 'code' => $code])
        ->assertStatus(422)->assertJsonPath('error.code', 'PHONE_SIGN_IN_INVALID_CODE');
});

test('a second request inside the cooldown sends nothing more', function (): void {
    phoneUser('+962790000116');

    $this->postJson(PHONE_CODE, ['phone' => '+962790000116'])->assertOk();
    $this->postJson(PHONE_CODE, ['phone' => '+962790000116'])->assertOk();

    expect(phoneSignInMessages())->toHaveCount(1);
});

test('asking for codes is throttled per number', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(PHONE_CODE, ['phone' => '+962790000117'])->assertOk();
    }

    $this->postJson(PHONE_CODE, ['phone' => '+962790000117'])
        ->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
});

// ── Numbers that may not use a code ─────────────────────────────────────────

test('an administrator, a suspended account and a closed registration get the same answer and no message', function (): void {
    phoneUser('+962790000121', ['account_type' => AccountType::ADMIN]);
    phoneUser('+962790000122', ['is_active' => false]);
    phoneUser('+962790000123');

    $eligible = $this->postJson(PHONE_CODE, ['phone' => '+962790000123'])->assertOk()->json();
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM_test'], 201)]);
    $sentBefore = count(phoneSignInMessages());

    $admin = $this->postJson(PHONE_CODE, ['phone' => '+962790000121'])->assertOk()->json();
    $suspended = $this->postJson(PHONE_CODE, ['phone' => '+962790000122'])->assertOk()->json();

    app(SettingServiceInterface::class)->set('auth', 'registration_enabled', false);
    Cache::flush();

    $unknown = $this->postJson(PHONE_CODE, ['phone' => '+962790000124'])->assertOk()->json();

    expect($admin)->toBe($eligible)
        ->and($suspended)->toBe($eligible)
        ->and($unknown)->toBe($eligible)
        ->and(count(phoneSignInMessages()))->toBe($sentBefore)
        ->and(PhoneSignInCode::query()->count())->toBe(1);
});

test('an administrator is never signed in with a code', function (): void {
    $admin = phoneUser('+962790000125', ['account_type' => AccountType::ADMIN]);

    // Planted directly, as if a code existed for the number anyway.
    PhoneSignInCode::query()->create([
        'phone_hash' => (string) DB::table('users')->where('id', $admin->id)->value('phone_hash'),
        'otp_hash' => bcrypt('123456'),
        'expires_at' => now()->addMinutes(5),
        'sent_at' => now(),
    ]);

    $this->postJson(PHONE_SIGN_IN, ['phone' => '+962790000125', 'code' => '123456'])
        ->assertStatus(422)->assertJsonPath('error.code', 'PHONE_SIGN_IN_INVALID_CODE');

    expect($admin->tokens()->count())->toBe(0);
});

// ── Registering ──────────────────────────────────────────────────────────────

test('a number with no account registers a user once a name is given, and the code survives the first try', function (): void {
    $this->postJson(PHONE_CODE, ['phone' => '+962790000131'])->assertOk();
    $code = lastPhoneSignInCode();

    $this->postJson(PHONE_SIGN_IN, ['phone' => '+962790000131', 'code' => $code])
        ->assertStatus(422)->assertJsonPath('error.code', 'REGISTRATION_DETAILS_REQUIRED');

    expect(User::query()->count())->toBe(0);

    $response = $this->postJson(PHONE_SIGN_IN, [
        'phone' => '+962790000131',
        'code' => $code,
        'name' => 'New Phone Person',
        'preferred_locale' => 'ar',
        'account_type' => 'admin',
    ]);

    $response->assertStatus(201)->assertJsonPath('data.abilities', ['user:access']);

    $user = User::query()->where('name', 'New Phone Person')->firstOrFail();

    expect($user->account_type)->toBe(AccountType::USER)
        ->and($user->email)->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->phone)->toBe('+962790000131')
        ->and($user->phone_verified_at)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->preferred_locale)->toBe('ar');

    $record = DB::table('audit_records')->where('action', AuditAction::ACCOUNT_CREATED)->first();

    expect(json_decode((string) $record?->context, true))->toBe(['active' => true, 'source' => 'phone']);
});

test('nothing handled by the flow is written to the audit trail or the codes table in the clear', function (): void {
    $this->postJson(PHONE_CODE, ['phone' => '+962790000132'])->assertOk();
    $code = lastPhoneSignInCode();

    $this->postJson(PHONE_SIGN_IN, ['phone' => '+962790000132', 'code' => $code, 'name' => 'Quiet Person'])->assertStatus(201);

    $written = json_encode([
        DB::table('audit_records')->get()->all(),
        DB::table('phone_sign_in_codes')->get()->all(),
    ]);

    expect($written)->not->toContain($code)->and($written)->not->toContain('962790000132');
});
