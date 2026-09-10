<?php

declare(strict_types=1);

use App\Modules\Auth\Models\PhoneVerification;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
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
    $this->seed(IntegrationProviderSeeder::class);

    // Route SMS through Twilio under Http::fake, the way SmsOtpMfaTest does, so a test
    // can read the message body exactly as the vendor would receive it. The platform
    // never returns a code, so intercepting the outgoing message is the only honest
    // way to learn one — and it proves the code was delivered rather than merely
    // written to a row.
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM_test'], 201)]);

    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();
    $twilio->setCredentials(['account_sid' => 'AC_test', 'auth_token' => 'tok_test']);
    $twilio->forceFill(['settings' => ['from' => '+15550000000'], 'is_active' => true])->save();
    IntegrationProvider::query()->where('driver', 'log')->update(['is_active' => false, 'is_default' => false]);
    IntegrationProvider::query()->where('driver', 'twilio')->update(['is_default' => true]);
});

// Confirming that somebody holds the number on their account.
//
// A number arrived by two routes and neither confirmed anything: an account could set
// its own and an administrator could set it for them. SMS-MFA enrolment proved
// possession, but that is a second factor rather than a property of the account.
//
// What is under test is mostly what the flow refuses — a code sent to a number that
// has since changed, a code answered too many times, a second send inside the
// cooldown — because those are the ways a verification state becomes a lie.

/**
 * An account with a number nobody has confirmed, and a token for it.
 *
 * @return array{0: User, 1: string}
 */
function withNumber(string $number = '+962790000111'): array
{
    $user = makeAccount([
        'name' => 'A Number Holder',
        'email' => 'holder'.uniqid().'@example.test',
    ]);

    $user->phone = $number;
    $user->save();

    return [$user->refresh(), $user->createToken('test-token', ['user:access'])->plainTextToken];
}

/**
 * The code in the most recently dispatched message.
 *
 * Read from the outgoing message rather than from the row, because only a hash is
 * stored — which is the point — and because a code nobody sent is not a code.
 */
function deliveredPhoneCode(): string
{
    $bodies = [];

    foreach (Http::recorded() as [$request]) {
        if (isset($request['Body'])) {
            $bodies[] = (string) $request['Body'];
        }
    }

    if ($bodies === [] || preg_match('/\b(\d{4,8})\b/', end($bodies), $matches) !== 1) {
        return '';
    }

    return $matches[1];
}

test('a number is unconfirmed until somebody answers a code sent to it', function (): void {
    [$user] = withNumber();

    expect($user->phone)->toBe('+962790000111')
        ->and($user->phone_verified_at)->toBeNull();
});

test('sending a code stores only its hash', function (): void {
    [$user, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')
        ->assertOk()
        // Masked: enough to say where it went, not a way of reading a number back out
        // of the platform.
        ->assertJsonPath('data.destination', '*********0111');

    $pending = PhoneVerification::query()->where('user_id', $user->id)->sole();

    expect($pending->otp_hash)->not->toBe('')
        ->and($pending->attempts)->toBe(0)
        ->and($pending->expires_at->isFuture())->toBeTrue();
});

test('answering the code confirms the number', function (): void {
    [$user, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify', [
        'code' => deliveredPhoneCode(),
    ])->assertOk()->assertJsonPath('data.phone_verified', true);

    expect($user->refresh()->phone_verified_at)->not->toBeNull()
        // The pending row goes: a confirmed number keeps no credential material.
        ->and(PhoneVerification::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('a code cannot be replayed once it has been answered', function (): void {
    [$user, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();
    $code = deliveredPhoneCode();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify', ['code' => $code])->assertOk();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PHONE_ALREADY_VERIFIED');
});

test('an expired code confirms nothing', function (): void {
    [$user, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();
    $code = deliveredPhoneCode();

    $this->travel(6)->minutes();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PHONE_VERIFICATION_INVALID_CODE');

    expect($user->refresh()->phone_verified_at)->toBeNull();
});

test('a code sent to the previous number cannot confirm a new one', function (): void {
    [$user, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();
    $code = deliveredPhoneCode();

    // The number moves after the code is on its way. Somebody answered the old
    // handset, which is no evidence at all about the new one.
    $user->phone = '+962790000222';
    $user->save();

    // Two requests, as production would run them. Without this the guard hands the
    // second one the account it resolved for the first, and the test would be asking
    // the platform about a number that had already changed underneath it.
    resetClient($this);

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PHONE_VERIFICATION_INVALID_CODE');

    expect($user->refresh()->phone_verified_at)->toBeNull()
        ->and(PhoneVerification::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('the code is discarded after the configured number of wrong answers', function (): void {
    app(SettingServiceInterface::class)->set('auth', 'otp_max_attempts', 3);
    Cache::flush();

    [$user, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();
    $code = deliveredPhoneCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 3) as $ignored) {
        $this->withToken($token)->postJson('/api/v1/auth/phone/verify', ['code' => $wrong])
            ->assertStatus(422);
    }

    // Gone, so the right code no longer works either: guessing costs a resend, and the
    // resend costs the cooldown.
    expect(PhoneVerification::query()->where('user_id', $user->id)->count())->toBe(0);

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify', ['code' => $code])
        ->assertStatus(422);

    expect($user->refresh()->phone_verified_at)->toBeNull();
});

test('a second code cannot be requested inside the cooldown', function (): void {
    [, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'PHONE_VERIFICATION_THROTTLED');
});

test('an account with no number has nothing to verify', function (): void {
    $user = makeAccount(['email' => 'numberless@example.test']);
    $token = $user->createToken('test-token', ['user:access'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PHONE_NUMBER_MISSING');
});

test('neither route takes an account identifier, so nobody verifies somebody else', function (): void {
    [$mine, $token] = withNumber();
    [$theirs] = withNumber('+962790000333');

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();

    // The only pending verification belongs to the caller. There is no parameter that
    // could have aimed this at the other account.
    expect(PhoneVerification::query()->where('user_id', $mine->id)->count())->toBe(1)
        ->and(PhoneVerification::query()->where('user_id', $theirs->id)->count())->toBe(0);
});

test('an unauthenticated caller verifies nothing', function (): void {
    $this->postJson('/api/v1/auth/phone/verify/send')->assertUnauthorized();
    $this->postJson('/api/v1/auth/phone/verify', ['code' => '123456'])->assertUnauthorized();
});

// ── The confirmation follows the number ──────────────────────────────────────

test('changing the number clears the confirmation, wherever the change comes from', function (): void {
    [$user, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();
    $this->withToken($token)->postJson('/api/v1/auth/phone/verify', [
        'code' => deliveredPhoneCode(),
    ])->assertOk();

    expect($user->refresh()->phone_verified_at)->not->toBeNull();

    // Straight onto the model, which is how a seeder or a console command would do it.
    // The accessor clears the confirmation, so no caller has to remember to.
    $user->phone = '+962790000444';
    $user->save();

    expect($user->refresh()->phone_verified_at)->toBeNull();
});

test('setting the number an account already has does not un-confirm it', function (): void {
    [$user, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();
    $this->withToken($token)->postJson('/api/v1/auth/phone/verify', [
        'code' => deliveredPhoneCode(),
    ])->assertOk();

    // Written in a form the platform canonicalises to the same number. Submitting what
    // is already stored is not a reason to make somebody prove it again.
    $user->phone = '+962 79 000 0111';
    $user->save();

    expect($user->refresh()->phone_verified_at)->not->toBeNull();
});

test('an administrator changing the number un-confirms it too', function (): void {
    [$user, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();
    $this->withToken($token)->postJson('/api/v1/auth/phone/verify', [
        'code' => deliveredPhoneCode(),
    ])->assertOk();

    $admin = tokenWithPermissions(['users.update']);

    // A different person now. The guard caches whoever it resolved last, so without
    // this the administrator's token is carried by a request the account holder is
    // still authenticated for.
    resetClient($this);

    $this->withToken($admin)->putJson('/api/v1/admin/users/'.$user->id, [
        'phone' => '+962790000555',
    ])->assertOk()->assertJsonPath('data.phone_verified', false);

    expect($user->refresh()->phone_verified_at)->toBeNull();
});

test('an administrator can see whether a number is confirmed and cannot confirm one', function (): void {
    [$user] = withNumber();
    $admin = tokenWithPermissions(['users.view']);

    $this->withToken($admin)->getJson('/api/v1/admin/users/'.$user->id)
        ->assertOk()
        ->assertJsonPath('data.phone_verified', false)
        ->assertJsonPath('data.phone_verified_at', null);

    // There is no administrative route that confirms somebody else's number, and the
    // whole value of the state is that a person answered a message.
    $this->withToken($admin)->postJson('/api/v1/admin/users/'.$user->id.'/phone/verify')
        ->assertNotFound();
});
// ── One policy, both flows ────────────────────────────────────────────────────
//
// MFA and phone verification send codes by the same mechanism and used to disagree
// about it: length, lifetime and cooldown were constants inside the MFA method, so an
// operator who changed them changed one of the two places that send a code. Both read
// OtpPolicy now, and these are the phone-verification half of that claim — the MFA
// half lives in SmsOtpMfaTest.

test('the configured code length is the length that arrives here too', function (): void {
    app(SettingServiceInterface::class)->updateGroup('auth', ['otp_length' => 8]);
    Cache::flush();

    [, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();

    $code = deliveredPhoneCode();

    expect($code)->toHaveLength(8);

    // And it is the answer the platform accepts, not merely eight digits in a message.
    $this->withToken($token)->postJson('/api/v1/auth/phone/verify', ['code' => $code])
        ->assertOk();
});

test('the configured lifetime governs when a code stops working', function (): void {
    app(SettingServiceInterface::class)->updateGroup('auth', ['otp_lifetime_seconds' => 900]);
    Cache::flush();

    [$user, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();

    // What the recipient is told, and what is true, are the same number.
    $body = '';
    foreach (Http::recorded() as [$request]) {
        if (isset($request['Body'])) {
            $body = (string) $request['Body'];
        }
    }
    expect($body)->toContain('15 minutes');

    $code = deliveredPhoneCode();

    // Past the five minutes that used to be fixed in code.
    $this->travel(10)->minutes();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify', ['code' => $code])
        ->assertOk();

    expect($user->refresh()->phone_verified_at)->not->toBeNull();
});

test('the configured cooldown governs when a second code may be asked for', function (): void {
    app(SettingServiceInterface::class)->updateGroup('auth', ['otp_resend_cooldown_seconds' => 120]);
    Cache::flush();

    [, $token] = withNumber();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();

    // A minute on: past the default thirty seconds, inside the configured two minutes.
    $this->travel(60)->seconds();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'PHONE_VERIFICATION_THROTTLED');

    $this->travel(61)->seconds();

    $this->withToken($token)->postJson('/api/v1/auth/phone/verify/send')->assertOk();
});
