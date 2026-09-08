<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Support\AuthCookie;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;

uses(RefreshDatabase::class);

const LOGOUT_PASSWORD = 'logout-password';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    $this->user = makeAccount([
        'name' => 'Signing Out',
        'email' => 'logout@example.com',
        'password' => LOGOUT_PASSWORD,
        'account_type' => AccountType::USER,
        'is_active' => true,
    ]);
});

/**
 * The auth cookie from a response, or null when it set none.
 */
function logoutCookie(mixed $response): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === AuthCookie::NAME) {
            return $cookie;
        }
    }

    return null;
}

function signInForLogout(mixed $test)
{
    return $test->postJson('/api/v1/auth/login', [
        'identifier' => 'logout@example.com',
        'password' => LOGOUT_PASSWORD,
    ]);
}

// ── The cookie is taken back ─────────────────────────────────────────────────

test('signing out clears the authentication cookie', function (): void {
    $token = signInForLogout($this)->json('data.token');
    resetClient($this);

    $cookie = logoutCookie(
        $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
            ->postJson('/api/v1/auth/logout')->assertOk()
    );

    expect($cookie)->not->toBeNull()
        ->and($cookie->getValue())->toBe('')
        ->and($cookie->getExpiresTime())->toBeLessThan(time());
});

test('the clearing cookie matches the one it removes', function (): void {
    // A browser scopes a cookie by name and path. A deletion written at a different
    // path leaves the original in place and signing out silently does nothing.
    $token = signInForLogout($this)->json('data.token');
    resetClient($this);

    $cleared = logoutCookie(
        $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
            ->postJson('/api/v1/auth/logout')
    );

    expect($cleared->getName())->toBe(AuthCookie::NAME)
        ->and($cleared->getPath())->toBe(AuthCookie::PATH)
        ->and($cleared->isHttpOnly())->toBeTrue()
        ->and($cleared->isSecure())->toBeTrue()
        ->and($cleared->getSameSite())->toBe(Cookie::SAMESITE_STRICT);
});

test('a bearer caller is given the clearing cookie too', function (): void {
    // Harmless for a client that holds none, and necessary for one that does: the
    // endpoint cannot tell which arrived without inspecting, and inspecting to decide
    // would be a branch with no benefit.
    $token = signInForLogout($this)->json('data.token');
    resetClient($this);

    expect(logoutCookie($this->withToken($token)->postJson('/api/v1/auth/logout')))->not->toBeNull();
});

// ── The token is genuinely gone ──────────────────────────────────────────────

test('the revoked token no longer authenticates over a cookie', function (): void {
    $token = signInForLogout($this)->json('data.token');
    resetClient($this);

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
        ->postJson('/api/v1/auth/logout')->assertOk();

    resetClient($this);

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

test('the revoked token no longer authenticates over a bearer header', function (): void {
    // Clearing the cookie is not the security boundary; deleting the token is. A copy
    // of the credential taken before sign-out must not keep working.
    $token = signInForLogout($this)->json('data.token');
    resetClient($this);

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
        ->postJson('/api/v1/auth/logout')->assertOk();

    resetClient($this);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('the token row is deleted rather than merely forgotten', function (): void {
    $token = signInForLogout($this)->json('data.token');
    resetClient($this);

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
        ->postJson('/api/v1/auth/logout')->assertOk();

    expect(PersonalAccessToken::findToken($token))->toBeNull();
});

// ── Signing out is per credential, as before ─────────────────────────────────

test('signing out of one session leaves another alone', function (): void {
    // Deliberately bearer on both requests. withCredentials() is sticky for the rest
    // of a test, and resetClient() clears headers rather than cookies — so mixing the
    // two here would leave the revoked cookie attached and the second request would be
    // refused for presenting the first credential, which is cookie precedence working
    // correctly and not the thing this test is about.
    /** @var User $user */
    $user = $this->user;

    $first = signInForLogout($this)->json('data.token');
    resetClient($this);
    $second = $user->createToken('other-device', [TokenAbility::USER_ACCESS->value])->plainTextToken;

    $this->withToken($first)->postJson('/api/v1/auth/logout')->assertOk();
    resetClient($this);

    $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();

    expect(PersonalAccessToken::findToken($first))->toBeNull()
        ->and(PersonalAccessToken::findToken($second))->not->toBeNull();
});

test('signing out is unreachable without a credential', function (): void {
    $this->postJson('/api/v1/auth/logout')->assertStatus(401);
});

test('a scoped credential still cannot sign out', function (string $ability): void {
    // Unchanged by the transport: logout requires a completed sign-in, and neither
    // scoped credential is one.
    /** @var User $user */
    $user = $this->user;
    $token = $user->createToken('scoped', [$ability])->plainTextToken;

    $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(403);

    expect(PersonalAccessToken::findToken($token))->not->toBeNull();
})->with([
    'mfa:enrol' => TokenAbility::MFA_ENROL->value,
    'email:verify' => TokenAbility::EMAIL_VERIFY->value,
]);

// ── Nothing is echoed ────────────────────────────────────────────────────────

test('the sign-out response never contains the token', function (): void {
    $token = signInForLogout($this)->json('data.token');
    resetClient($this);

    $body = $this->withCredentials()->withUnencryptedCookie(AuthCookie::NAME, $token)
        ->postJson('/api/v1/auth/logout')->getContent();

    expect($body)->not->toContain($token)
        ->and($body)->not->toContain(AuthCookie::NAME);
});
