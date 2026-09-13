<?php

declare(strict_types=1);

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Contracts\SocialIdentityRegistryContract;
use App\Modules\User\Models\SocialIdentity;
use App\Modules\User\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * Account recovery by email (ADR 0050 §12): a signed, expiring, single-use link that sets
 * a password, answered identically whether or not the address holds an account.
 */

uses(RefreshDatabase::class);

const RESET_PAGE = 'https://client.example.test/reset-password';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);

    app(SettingServiceInterface::class)->set('auth', 'password_reset_url', RESET_PAGE);
    Cache::flush();

    Notification::fake();

    // The link is sent after the response, so an address that exists and one that does
    // not take the same time. Here it runs at once, so the test can see it.
    $this->withoutDefer();
});

/**
 * Ask for a link, and return the token that was mailed, if any was.
 */
function requestResetToken(mixed $test, User $user): ?string
{
    $test->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();

    $token = null;

    Notification::assertSentTo($user, ResetPassword::class, static function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    return $token;
}

test('asking for a link answers the same whether or not the address holds an account', function (): void {
    makeAccount(['email' => 'exists@example.test']);

    $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'exists@example.test']);
    $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.test']);

    expect($known->status())->toBe(200)
        ->and($unknown->status())->toBe(200)
        ->and($known->json())->toBe($unknown->json());
});

test('the link goes to the configured page, carrying the token and the address', function (): void {
    $user = makeAccount(['email' => 'link-target@example.test']);

    $token = requestResetToken($this, $user);

    Notification::assertSentTo($user, ResetPassword::class, static function (ResetPassword $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, RESET_PAGE.'?')
            && str_contains($url, 'token='.$notification->token)
            && str_contains($url, 'email='.rawurlencode('link-target@example.test'));
    });

    expect($token)->toBeString();
});

test('nothing is sent to an address that holds no account', function (): void {
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody-at-all@example.test'])->assertOk();

    Notification::assertNothingSent();
});

test('nothing is sent while no reset page is configured', function (): void {
    app(SettingServiceInterface::class)->set('auth', 'password_reset_url', null);
    Cache::flush();

    makeAccount(['email' => 'unconfigured@example.test']);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unconfigured@example.test'])->assertOk();

    Notification::assertNothingSent();
});

test('a valid token sets the new password and signs every session out', function (): void {
    $user = makeAccount(['email' => 'resets@example.test']);
    $user->createToken('old-session', ['user:access']);

    $token = requestResetToken($this, $user);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'resets@example.test',
        'token' => $token,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    $fresh = $user->fresh();

    expect(Hash::check('a-brand-new-password', (string) $fresh->password))->toBeTrue()
        ->and($fresh->tokens()->count())->toBe(0);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'resets@example.test',
        'password' => 'a-brand-new-password',
    ])->assertOk();
});

test('a token cannot be used twice', function (): void {
    $user = makeAccount(['email' => 'reuse@example.test']);
    $token = requestResetToken($this, $user);

    $reset = fn (string $password) => $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'reuse@example.test',
        'token' => $token,
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    $reset('first-new-password')->assertOk();
    $reset('second-new-password')->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');
});

test('a wrong token, or an address with no account, is refused with one answer', function (): void {
    makeAccount(['email' => 'wrong-token@example.test']);

    $wrongToken = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'wrong-token@example.test',
        'token' => 'not-a-token-this-platform-issued',
        'password' => 'another-password',
        'password_confirmation' => 'another-password',
    ]);

    $noAccount = $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'no-account@example.test',
        'token' => 'not-a-token-this-platform-issued',
        'password' => 'another-password',
        'password_confirmation' => 'another-password',
    ]);

    $wrongToken->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');

    expect($noAccount->json('error'))->toBe($wrongToken->json('error'));
});

test('an account that signs in only through a provider can set a password, and then unlink it', function (): void {
    $user = makeAccount(['email' => 'social-only@example.test']);
    DB::table('users')->where('id', $user->id)->update(['password' => null]);
    $identity = app(SocialIdentityRegistryContract::class)->link($user->fresh(), 'google', 'social-only-subject', null)->identity;

    $token = requestResetToken($this, $user->fresh());

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'social-only@example.test',
        'token' => $token,
        'password' => 'recovered-password',
        'password_confirmation' => 'recovered-password',
    ])->assertOk();

    app(SocialIdentityRegistryContract::class)->unlink($user->fresh(), $identity->id);

    expect(SocialIdentity::query()->whereKey($identity->id)->linked()->exists())->toBeFalse();
});

test('recovering an account does not verify its address', function (): void {
    $user = makeAccount(['email' => 'stays-unverified@example.test', 'email_verified_at' => null]);
    $token = requestResetToken($this, $user);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'stays-unverified@example.test',
        'token' => $token,
        'password' => 'unverified-still',
        'password_confirmation' => 'unverified-still',
    ])->assertOk();

    expect(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->toBeNull();
});

test('asking for links is throttled', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'flooded@example.test'])->assertOk();
    }

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'flooded@example.test'])
        ->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
});
