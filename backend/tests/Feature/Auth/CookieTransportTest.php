<?php

declare(strict_types=1);

use App\Modules\Auth\Contracts\MfaManagerContract;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Support\AuthCookie;
use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Cookie;

uses(RefreshDatabase::class);

const COOKIE_PASSWORD = 'cookie-transport-password';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

/**
 * A TOTP code from a chosen time slice.
 *
 * Google2FA reads the wall clock, which Carbon's fake clock does not move, so
 * travelling forward does not produce a newer code. Replay protection accepts only a
 * slice strictly newer than the last one used (ADR 0013), and enrolment consumes the
 * current one — so a challenge afterwards has to ask for the next.
 *
 * Named locally rather than reusing MfaTest's identical helper: Pest shares one global
 * function namespace, so a second definition of that name would be fatal, and calling
 * the other file's would make this suite fail when run on its own.
 */
function cookieOtpAt(string $secret, int $sliceOffset = 0): string
{
    $google2fa = app(Google2FA::class);

    return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $sliceOffset);
}

/**
 * Enrol and confirm TOTP for an account, returning the shared secret.
 */
function enrolTotp(User $user): string
{
    $manager = app(MfaManagerContract::class);
    $secret = $manager->enrol($user, MfaType::TOTP)->secret;
    $manager->confirm($user, MfaType::TOTP, (new Google2FA)->getCurrentOtp($secret));

    return $secret;
}

/**
 * An administrator with both prerequisites satisfied.
 *
 * Signing in still does not hand one an access token directly: MFA is mandatory for
 * administrators, so the login endpoint answers with a challenge and the token is
 * issued when that challenge is completed. Every test here that needs an
 * administrative session therefore goes through adminSession(), which is also the
 * only path a real administrator has.
 *
 * @return array{user: User, secret: string}
 */
function readyAdmin(string $email = 'cookie-admin@example.com'): array
{
    $admin = makeAccount([
        'name' => 'Cookie Admin',
        'email' => $email,
        'password' => COOKIE_PASSWORD,
        'account_type' => AccountType::ADMIN,
        'is_active' => true,
    ]);

    app(AdminRbacContract::class)->syncRoles($admin, ['administrator']);

    return ['user' => $admin->refresh(), 'secret' => enrolTotp($admin)];
}

/**
 * Sign an administrator in completely: password, then second factor.
 *
 * Returns the response that carries the access token, which is the one that sets the
 * cookie.
 */
function adminSession(mixed $test, array $admin)
{
    $mfaToken = signIn($test, $admin['user']->email)->json('data.mfa_token');

    return $test->postJson('/api/v1/auth/mfa/challenge', [
        'mfa_token' => $mfaToken,
        'code' => cookieOtpAt($admin['secret'], 1),
    ]);
}

/**
 * A regular account with no second factor, so signing in yields a token directly.
 */
function plainUser(string $email = 'cookie-user@example.com'): User
{
    return makeAccount([
        'name' => 'Cookie User',
        'email' => $email,
        'password' => COOKIE_PASSWORD,
        'account_type' => AccountType::USER,
        'is_active' => true,
    ]);
}

/**
 * The auth cookie from a response, or null when it set none.
 */
function authCookie(mixed $response): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === AuthCookie::NAME) {
            return $cookie;
        }
    }

    return null;
}

function signIn(mixed $test, string $email = 'cookie-admin@example.com')
{
    return $test->postJson('/api/v1/auth/login', [
        'identifier' => $email,
        'password' => COOKIE_PASSWORD,
    ]);
}

// ── The cookie is set, with exactly the declared attributes ──────────────────

test('signing in sets an authentication cookie', function (): void {
    plainUser();

    expect(authCookie(signIn($this, 'cookie-user@example.com')))->not->toBeNull();
});

test('the cookie carries exactly the four declared attributes', function (): void {
    plainUser();

    $cookie = authCookie(signIn($this, 'cookie-user@example.com'));

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_STRICT)
        ->and($cookie->getPath())->toBe('/api')
        // No Domain: the cookie belongs to the origin that set it and is not widened
        // to siblings.
        ->and($cookie->getDomain())->toBeNull();
});

test('the cookie holds the very token the response returned', function (): void {
    plainUser();

    $response = signIn($this, 'cookie-user@example.com');

    expect(authCookie($response)->getValue())->toBe($response->json('data.token'));
});

test('the cookie value resolves to a real Sanctum token with real abilities', function (): void {
    // The property the whole design rests on. A TransientToken would answer true to
    // every ability check and the perimeter would be gone.
    $admin = readyAdmin();

    $token = PersonalAccessToken::findToken(authCookie(adminSession($this, $admin))->getValue());

    expect($token)->toBeInstanceOf(PersonalAccessToken::class)
        ->and($token->abilities)->toBe([TokenAbility::ADMIN_ACCESS->value])
        ->and($token->can(TokenAbility::ADMIN_ACCESS->value))->toBeTrue()
        ->and($token->can(TokenAbility::USER_ACCESS->value))->toBeFalse();
});

// ── Both scoped credentials travel the same way ─────────────────────────────

test('an enrolment credential is carried by the cookie too', function (): void {
    makeAccount([
        'email' => 'unenrolled@example.com',
        'password' => COOKIE_PASSWORD,
        'account_type' => AccountType::ADMIN,
    ]);

    $response = signIn($this, 'unenrolled@example.com');

    expect($response->json('data.mfa_setup_required'))->toBeTrue()
        ->and(authCookie($response)?->getValue())->toBe($response->json('data.enrolment_token'));
});

test('a verification credential is carried by the cookie too', function (): void {
    makeAccount([
        'email' => 'unverified@example.com',
        'password' => COOKIE_PASSWORD,
        'account_type' => AccountType::ADMIN,
        'email_verified_at' => null,
    ]);

    $response = signIn($this, 'unverified@example.com');

    expect($response->json('data.email_verification_required'))->toBeTrue()
        ->and(authCookie($response)?->getValue())->toBe($response->json('data.verification_token'));
});

// ── The MFA challenge token stays out of the cookie ─────────────────────────

test('an MFA challenge sets no authentication cookie', function (): void {
    // mfa_token is not a Sanctum token and grants nothing. Putting it in the auth
    // cookie would confuse a credential with a correlation identifier.
    $user = makeAccount(['email' => 'mfa-user@example.com', 'password' => COOKIE_PASSWORD]);
    enrolTotp($user);

    $response = signIn($this, 'mfa-user@example.com');

    expect($response->json('data.mfa_required'))->toBeTrue()
        ->and($response->json('data.mfa_token'))->toBeString()
        ->and(authCookie($response))->toBeNull();
});

test('completing the challenge is what sets the cookie', function (): void {
    $user = makeAccount(['email' => 'mfa-user2@example.com', 'password' => COOKIE_PASSWORD]);
    $secret = enrolTotp($user);

    $mfaToken = signIn($this, 'mfa-user2@example.com')->json('data.mfa_token');

    $response = $this->postJson('/api/v1/auth/mfa/challenge', [
        'mfa_token' => $mfaToken,
        'code' => cookieOtpAt($secret, 1),
    ]);

    $response->assertOk();

    expect(authCookie($response)?->getValue())->toBe($response->json('data.token'));
});

test('the enrolment exchange replaces the cookie with the access token', function (): void {
    makeAccount([
        'email' => 'exchange@example.com',
        'password' => COOKIE_PASSWORD,
        'account_type' => AccountType::ADMIN,
    ]);

    $enrolment = signIn($this, 'exchange@example.com')->json('data.enrolment_token');

    $secret = $this->withToken($enrolment)
        ->postJson('/api/v1/auth/mfa/enrol', ['type' => 'totp'])
        ->json('data.secret');

    $response = $this->withToken($enrolment)->postJson('/api/v1/auth/mfa/verify', [
        'type' => 'totp',
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ]);

    $response->assertOk();

    // The enrolment credential was deleted, so a cookie still carrying it would leave
    // the browser presenting a token that no longer exists.
    expect(authCookie($response)?->getValue())->toBe($response->json('data.token'))
        ->and(PersonalAccessToken::findToken($enrolment))->toBeNull();
});

// ── Authenticating with the cookie ──────────────────────────────────────────

/*
 * withCredentials()->withUnencryptedCookie(), and both halves are load-bearing.
 *
 * getJson() and postJson() send no cookies at all unless withCredentials() was
 * called — prepareCookiesForJsonRequest() returns an empty array otherwise. Without
 * it the request arrives with an empty cookie bag and every assertion here fails as
 * a plain 401, which looks exactly like a transport that does not work. It is also
 * the honest simulation: a browser sends cookies on a same-origin request, and the
 * Admin's fetch client says so explicitly.
 *
 * withUnencryptedCookie(), not withCookie().
 *
 * The test helper encrypts by default because the framework assumes EncryptCookies,
 * which lives in the `web` middleware group. API routes have neither it nor
 * AddQueuedCookiesToResponse, so the cookie is written and read raw in production —
 * and withCookie() would hand the request ciphertext no middleware decrypts, which
 * fails as a plain 401 and looks exactly like a transport that does not work.
 */

test('a cookie authenticates a request with no Authorization header', function (): void {
    $admin = readyAdmin();

    $token = authCookie(adminSession($this, $admin))->getValue();
    resetClient($this);

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'cookie-admin@example.com');
});

test('a cookie reaches an administrative endpoint through the whole perimeter', function (): void {
    $admin = readyAdmin();

    $token = authCookie(adminSession($this, $admin))->getValue();
    resetClient($this);

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
        ->getJson('/api/v1/admin/settings')
        ->assertOk();
});

test('bearer authentication still works, untouched', function (): void {
    $admin = readyAdmin();

    $token = adminSession($this, $admin)->json('data.token');
    resetClient($this);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    $this->withToken($token)->getJson('/api/v1/admin/settings')->assertOk();
});

test('the cookie wins when both are presented', function (): void {
    $admin = readyAdmin();
    $other = makeAccount(['email' => 'other-admin@example.com', 'account_type' => AccountType::USER]);

    $cookieToken = authCookie(adminSession($this, $admin))->getValue();
    $bearerToken = $other->createToken('other', [TokenAbility::USER_ACCESS->value])->plainTextToken;

    resetClient($this);

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $cookieToken)
        ->withToken($bearerToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $admin['user']->id);
});

test('an unknown cookie value is refused rather than falling through to bearer', function (): void {
    // Cookie-first means a present-but-invalid cookie is the credential presented,
    // and a wrong credential is a refusal. Falling back would let a stale cookie be
    // silently ignored, which is a confusing way to be authenticated as someone else.
    $admin = readyAdmin()['user'];
    $bearer = $admin->createToken('bearer', [TokenAbility::ADMIN_ACCESS->value])->plainTextToken;

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, 'not-a-real-token')
        ->withToken($bearer)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

test('an empty cookie falls through to bearer', function (): void {
    // An empty value is the absence of a credential rather than a wrong one, which is
    // what a cleared cookie looks like before the browser drops it.
    $admin = readyAdmin()['user'];
    $bearer = $admin->createToken('bearer', [TokenAbility::ADMIN_ACCESS->value])->plainTextToken;

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, '')
        ->withToken($bearer)
        ->getJson('/api/v1/auth/me')
        ->assertOk();
});

// ── The perimeter is unchanged under cookie transport ───────────────────────

test('a cookie carrying a user token is refused at the admin perimeter', function (): void {
    $user = makeAccount(['email' => 'plain-user@example.com', 'account_type' => AccountType::USER]);
    $token = $user->createToken('user', [TokenAbility::USER_ACCESS->value])->plainTextToken;

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403);
});

test('a cookie cannot bypass email verification', function (): void {
    $admin = makeAccount([
        'email' => 'unverified-cookie@example.com',
        'account_type' => AccountType::ADMIN,
        'email_verified_at' => null,
    ]);
    app(AdminRbacContract::class)->syncRoles($admin, ['administrator']);

    // Minted directly: a token that predates the rule, which is exactly what the
    // perimeter stage exists to catch.
    $token = $admin->createToken('legacy', [TokenAbility::ADMIN_ACCESS->value])->plainTextToken;

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
});

test('a cookie cannot bypass MFA', function (): void {
    // An administrator who has not enrolled receives an enrolment credential, and the
    // cookie carries that and not an access token. There is no cookie path to
    // admin:access that skips the second factor.
    makeAccount([
        'email' => 'no-mfa@example.com',
        'password' => COOKIE_PASSWORD,
        'account_type' => AccountType::ADMIN,
    ]);

    $cookieValue = authCookie(signIn($this, 'no-mfa@example.com'))->getValue();
    resetClient($this);

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $cookieValue)
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403);

    expect(PersonalAccessToken::findToken($cookieValue)->abilities)
        ->toBe([TokenAbility::MFA_ENROL->value]);
});

test('the scoped verification credential keeps its scope over a cookie', function (string $method, string $uri, int $status): void {
    $admin = makeAccount([
        'email' => 'scoped-cookie@example.com',
        'account_type' => AccountType::ADMIN,
        'email_verified_at' => null,
    ]);

    $token = $admin->createToken('scoped', [TokenAbility::EMAIL_VERIFY->value])->plainTextToken;

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)->json($method, $uri)->assertStatus($status);
})->with([
    'its own endpoint' => ['POST', '/api/v1/auth/email/verify/send', 200],
    'me' => ['GET', '/api/v1/auth/me', 403],
    'logout' => ['POST', '/api/v1/auth/logout', 403],
    'mfa status' => ['GET', '/api/v1/auth/mfa', 403],
    'mfa enrolment' => ['POST', '/api/v1/auth/mfa/enrol', 403],
    'an admin route' => ['GET', '/api/v1/admin/settings', 403],
]);

test('the scoped enrolment credential keeps its scope over a cookie', function (string $method, string $uri, int $status): void {
    $admin = makeAccount(['email' => 'enrol-cookie@example.com', 'account_type' => AccountType::ADMIN]);
    $token = $admin->createToken('scoped', [TokenAbility::MFA_ENROL->value])->plainTextToken;

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)->json($method, $uri)->assertStatus($status);
})->with([
    'me' => ['GET', '/api/v1/auth/me', 403],
    'logout' => ['POST', '/api/v1/auth/logout', 403],
    'mfa status' => ['GET', '/api/v1/auth/mfa', 403],
    'an admin route' => ['GET', '/api/v1/admin/settings', 403],
    'the verification send endpoint' => ['POST', '/api/v1/auth/email/verify/send', 403],
]);

// ── The cookie is not readable by a client, and not echoed ──────────────────

test('no response body ever contains the cookie name', function (): void {
    plainUser();

    $response = signIn($this, 'cookie-user@example.com');

    expect($response->getContent())->not->toContain(AuthCookie::NAME);
});

test('me does not echo the presented credential back', function (): void {
    $admin = readyAdmin();

    $token = authCookie(adminSession($this, $admin))->getValue();
    resetClient($this);

    $body = $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)->getJson('/api/v1/auth/me')->getContent();

    expect($body)->not->toContain($token);
});

test('the signed verification link works while an auth cookie is present', function (): void {
    // The link is pathed under /api, so the cookie is attached to it. Its credential
    // is the signature, and the cookie must make no difference either way.
    $admin = makeAccount([
        'email' => 'link-with-cookie@example.com',
        'account_type' => AccountType::ADMIN,
        'email_verified_at' => null,
    ]);

    $token = $admin->createToken('scoped', [TokenAbility::EMAIL_VERIFY->value])->plainTextToken;

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
        ->getJson(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $admin->id,
            'hash' => sha1($admin->getEmailForVerification()),
        ]))
        ->assertOk();

    expect($admin->refresh()->hasVerifiedEmail())->toBeTrue();
});
