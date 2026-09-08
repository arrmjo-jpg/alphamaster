<?php

declare(strict_types=1);

use App\Modules\Auth\Services\EmailVerificationService;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

const VERIFY_PASSWORD = 'correct-horse-battery';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    Notification::fake();

    // Explicitly unverified. makeAccount() verifies by default, because an
    // administrative endpoint now requires a verified address and almost every other
    // test means an account that finished signing up.
    $this->unverified = makeAccount([
        'name' => 'Unverified Person',
        'email' => 'unverified@example.com',
        'password' => VERIFY_PASSWORD,
        'account_type' => AccountType::USER,
        'is_active' => true,
        'email_verified_at' => null,
    ]);

    $this->token = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'unverified@example.com',
        'password' => VERIFY_PASSWORD,
    ])->json('data.token');
});

/**
 * The signed URL the framework's notification would put in the mail.
 */
function verificationLink(User $user, ?string $hash = null, ?int $minutes = 60): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes($minutes),
        ['id' => $user->id, 'hash' => $hash ?? sha1($user->getEmailForVerification())]
    );
}

// ── Sending the link ─────────────────────────────────────────────────────────

test('an authenticated account can ask for a verification link', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/auth/email/verify/send')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email_verified', false);

    Notification::assertSentTo($this->unverified, VerifyEmail::class);
});

test('the link is not reachable without signing in', function (): void {
    $this->postJson('/api/v1/auth/email/verify/send')->assertStatus(401);

    Notification::assertNothingSent();
});

test('the endpoint takes no address, so it cannot be aimed at a stranger', function (): void {
    // An address in the payload is ignored; the mail goes to the account that is
    // signed in. Otherwise this is an open relay for bothering people.
    $victim = makeAccount(['email' => 'victim@example.com', 'password' => VERIFY_PASSWORD, 'email_verified_at' => null]);

    $this->withToken($this->token)
        ->postJson('/api/v1/auth/email/verify/send', ['email' => 'victim@example.com'])
        ->assertOk();

    Notification::assertSentTo($this->unverified, VerifyEmail::class);
    Notification::assertNotSentTo($victim, VerifyEmail::class);
});

test('asking again too soon is refused with the time remaining', function (): void {
    $this->withToken($this->token)->postJson('/api/v1/auth/email/verify/send')->assertOk();

    $response = $this->withToken($this->token)->postJson('/api/v1/auth/email/verify/send');

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'EMAIL_VERIFICATION_THROTTLED')
        ->assertHeader('Retry-After');

    expect($response->json('error.details.retry_after'))->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(EmailVerificationService::RESEND_COOLDOWN_SECONDS);

    // Exactly one mail, not two.
    Notification::assertSentToTimes($this->unverified, VerifyEmail::class, 1);
});

test('the cooldown expires and another link may be asked for', function (): void {
    $this->withToken($this->token)->postJson('/api/v1/auth/email/verify/send')->assertOk();

    $this->travel(EmailVerificationService::RESEND_COOLDOWN_SECONDS + 1)->seconds();

    $this->withToken($this->token)->postJson('/api/v1/auth/email/verify/send')->assertOk();

    Notification::assertSentToTimes($this->unverified, VerifyEmail::class, 2);
});

test('an already verified account is told so and is sent nothing', function (): void {
    $this->unverified->markEmailAsVerified();

    $this->withToken($this->token)
        ->postJson('/api/v1/auth/email/verify/send')
        ->assertOk()
        ->assertJsonPath('data.email_verified', true);

    Notification::assertNothingSent();
});

// ── A valid link ─────────────────────────────────────────────────────────────

test('a valid link verifies the address', function (): void {
    expect($this->unverified->hasVerifiedEmail())->toBeFalse();

    $this->getJson(verificationLink($this->unverified))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email_verified', true);

    expect($this->unverified->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('verification writes the timestamp', function (): void {
    expect($this->unverified->email_verified_at)->toBeNull();

    $before = now()->subSecond();
    $this->getJson(verificationLink($this->unverified))->assertOk();
    $after = now()->addSecond();

    $verifiedAt = $this->unverified->refresh()->email_verified_at;

    expect($verifiedAt)->not->toBeNull()
        ->and($verifiedAt->between($before, $after))->toBeTrue();
});

test('the link needs no token, because the signature is the credential', function (): void {
    // It arrives from a mail client, which holds no bearer token and never will.
    $this->getJson(verificationLink($this->unverified))->assertOk();

    expect($this->unverified->refresh()->hasVerifiedEmail())->toBeTrue();
});

// ── An invalid or expired link ───────────────────────────────────────────────

test('an expired link is refused', function (): void {
    $link = verificationLink($this->unverified, minutes: 60);

    $this->travel(61)->minutes();

    $this->getJson($link)->assertStatus(403);

    expect($this->unverified->refresh()->hasVerifiedEmail())->toBeFalse();
});

test('a tampered link is refused', function (string $mutation): void {
    $link = verificationLink($this->unverified);

    $tampered = match ($mutation) {
        'signature removed' => strtok($link, '?'),
        'signature altered' => preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $link),
        'expiry extended' => preg_replace('/expires=\d+/', 'expires='.now()->addYear()->getTimestamp(), $link),
    };

    $this->getJson((string) $tampered)->assertStatus(403);

    expect($this->unverified->refresh()->hasVerifiedEmail())->toBeFalse();
})->with(['signature removed', 'signature altered', 'expiry extended']);

test('a link naming an account that does not exist is refused', function (): void {
    $ghost = makeAccount(['email' => 'ghost@example.com', 'password' => VERIFY_PASSWORD, 'email_verified_at' => null]);
    $link = verificationLink($ghost);
    $ghost->delete();

    $this->getJson($link)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'INVALID_VERIFICATION_LINK');
});

test('a link issued for an address that has since changed is refused', function (): void {
    // The signature still holds — it signed the old hash — so only the hash check
    // catches this. Without it the new address would be marked verified on the
    // strength of a mail delivered to the old one.
    $link = verificationLink($this->unverified);

    $this->unverified->forceFill(['email' => 'moved@example.com'])->save();

    $this->getJson($link)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'INVALID_VERIFICATION_LINK');

    expect($this->unverified->refresh()->hasVerifiedEmail())->toBeFalse();
});

test('a validly signed link carrying the wrong hash is refused', function (): void {
    $this->getJson(verificationLink($this->unverified, hash: sha1('someone@else.test')))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'INVALID_VERIFICATION_LINK');

    expect($this->unverified->refresh()->hasVerifiedEmail())->toBeFalse();
});

test('one account cannot verify another', function (): void {
    $other = makeAccount(['email' => 'other@example.com', 'password' => VERIFY_PASSWORD, 'email_verified_at' => null]);

    // A link built for this account, pointed at the other one's id. The signature
    // covers both parameters, so this is refused before the hash is even compared.
    $link = str_replace($this->unverified->id, $other->id, verificationLink($this->unverified));

    $this->getJson($link)->assertStatus(403);

    expect($other->refresh()->hasVerifiedEmail())->toBeFalse();
});

// ── Verifying twice ──────────────────────────────────────────────────────────

test('following the same link twice is not an error', function (): void {
    // A mail client that prefetches links, a double click, and a browser retry all
    // arrive here a second time.
    $link = verificationLink($this->unverified);

    $this->getJson($link)->assertOk();
    $this->getJson($link)->assertOk()->assertJsonPath('data.email_verified', true);

    expect($this->unverified->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('a second visit does not move the timestamp', function (): void {
    // It records when the address was verified, not when that was last confirmed.
    $link = verificationLink($this->unverified);

    $this->getJson($link)->assertOk();
    $first = $this->unverified->refresh()->email_verified_at;

    $this->travel(5)->minutes();

    $this->getJson($link)->assertOk();

    expect($this->unverified->refresh()->email_verified_at->equalTo($first))->toBeTrue();
});

// ── /auth/me reflects the state ──────────────────────────────────────────────

test('me reports an unverified address', function (): void {
    $this->withToken($this->token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.email_verified_at', null);
});

test('me reports a verified address and when it happened', function (): void {
    $this->getJson(verificationLink($this->unverified))->assertOk();

    $response = $this->withToken($this->token)->getJson('/api/v1/auth/me');

    $response->assertOk()->assertJsonPath('data.email_verified', true);

    expect($response->json('data.email_verified_at'))->toBeString()
        ->and($response->json('data.email_verified_at'))
        ->toBe($this->unverified->refresh()->email_verified_at->toIso8601String());
});

// ── Nothing else moved ───────────────────────────────────────────────────────

test('an unverified regular account still signs in and still holds its token', function (): void {
    // Verification gates administrative access and nothing else. A regular account is
    // reported as unverified and is not stopped for it.
    expect($this->token)->toBeString();

    $this->withToken($this->token)->getJson('/api/v1/auth/me')->assertOk();
});

test('an unverified administrator is now stopped before MFA enrolment', function (): void {
    makeAccount([
        'name' => 'Unverified Admin',
        'email' => 'unverified-admin@example.com',
        'password' => VERIFY_PASSWORD,
        'account_type' => AccountType::ADMIN,
        'is_active' => true,
        'email_verified_at' => null,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'unverified-admin@example.com',
        'password' => VERIFY_PASSWORD,
    ])
        ->assertOk()
        ->assertJsonPath('data.email_verification_required', true)
        ->assertJsonPath('data.abilities', ['email:verify']);
});

// ── The mail itself ──────────────────────────────────────────────────────────

test('the notification is the framework, not this platform', function (): void {
    // The architecture suite forbids Auth from importing the Notification module, and
    // routing this one mail through a Core contract to reach it would be more
    // machinery than the mail is worth. The framework's own notification crosses no
    // boundary at all.
    $this->withToken($this->token)->postJson('/api/v1/auth/email/verify/send')->assertOk();

    Notification::assertSentTo($this->unverified, VerifyEmail::class);
});

test('the mail carries a link this platform will honour', function (): void {
    $this->withToken($this->token)->postJson('/api/v1/auth/email/verify/send')->assertOk();

    Notification::assertSentTo($this->unverified, VerifyEmail::class, function (VerifyEmail $notification): bool {
        $mail = $notification->toMail($this->unverified);
        $url = $mail->actionUrl;

        // Follow the URL the recipient would actually be given.
        $this->getJson($url)->assertOk();

        return str_contains((string) $url, '/api/v1/auth/email/verify/');
    });

    expect($this->unverified->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('the mail reads in Arabic for an Arabic request', function (): void {
    app()->setLocale('ar');

    $mail = (new VerifyEmail)->toMail($this->unverified);

    expect($mail->subject)->toBe('وثّق بريدك الإلكتروني')
        ->and($mail->actionText)->toBe('توثيق البريد الإلكتروني');
});
