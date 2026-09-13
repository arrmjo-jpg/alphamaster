<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * Requests refused at authentication are metered (ADR 0046, closing ADR 0029 item 22).
 *
 * The central limiter never saw them: Laravel sorts `Authenticate` ahead of the api
 * group, so a forged or expired token was answered 401 before any limit applied. These
 * pin the fix and, as much, what it leaves alone — a credential that authenticates is
 * never refused by it, a wrong password is not counted by it, and an outage of the
 * counter cannot turn a refusal into anything else.
 */
uses(RefreshDatabase::class);

const REJECTED_CEILING = 3;

const PROTECTED_ROUTE = '/api/v1/auth/mfa';

beforeEach(function (): void {
    // The container runs against Redis, where a counter outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    // Lowered so exhausting it takes a handful of requests — and doubles as proof
    // that the ceiling is the operator's anonymous one, read from Settings.
    app(SettingServiceInterface::class)->set('rate_limit', 'public_read_per_minute', REJECTED_CEILING);
});

/**
 * A request carrying a credential that authenticates nobody.
 */
function withForgedToken(mixed $test): mixed
{
    resetClient($test);

    return $test->withToken('1|this-token-was-never-issued');
}

test('a caller refused at authentication is told to wait once past the anonymous ceiling', function (): void {
    for ($i = 0; $i < REJECTED_CEILING; $i++) {
        withForgedToken($this)->getJson(PROTECTED_ROUTE)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    withForgedToken($this)->getJson(PROTECTED_ROUTE)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS')
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Remaining', '0');
});

test('a request with no credential at all is counted the same way', function (): void {
    for ($i = 0; $i < REJECTED_CEILING; $i++) {
        resetClient($this);
        $this->getJson(PROTECTED_ROUTE)->assertStatus(401);
    }

    resetClient($this);
    $this->getJson(PROTECTED_ROUTE)->assertStatus(429);
});

test('a credential that authenticates is served from an address that is over the ceiling', function (): void {
    for ($i = 0; $i <= REJECTED_CEILING; $i++) {
        withForgedToken($this)->getJson(PROTECTED_ROUTE);
    }

    withForgedToken($this)->getJson(PROTECTED_ROUTE)->assertStatus(429);

    // A colleague behind the same address, signed in properly. Nothing about their
    // authentication may change because somebody else's did not succeed.
    $account = makeAccount([
        'name' => 'Colleague',
        'email' => 'colleague@example.com',
        'account_type' => AccountType::USER,
        'is_active' => true,
    ]);
    $token = $account->createToken('colleague')->plainTextToken;

    resetClient($this);
    $this->withToken($token)->getJson(PROTECTED_ROUTE)->assertOk();
});

test('the count is per address', function (): void {
    for ($i = 0; $i <= REJECTED_CEILING; $i++) {
        withForgedToken($this)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->getJson(PROTECTED_ROUTE);
    }

    withForgedToken($this)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->getJson(PROTECTED_ROUTE)
        ->assertStatus(429);

    withForgedToken($this)->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
        ->getJson(PROTECTED_ROUTE)
        ->assertStatus(401);
});

test('a wrong password is not a rejected credential, and does not spend the count', function (): void {
    makeAccount([
        'name' => 'Login Target',
        'email' => 'login-target@example.com',
        'password' => 'the-right-password',
        'account_type' => AccountType::USER,
        'is_active' => true,
    ]);

    // Below both the login throttle and the auth class ceiling, so each is a plain
    // INVALID_CREDENTIALS answer from the login endpoint itself.
    for ($i = 0; $i < REJECTED_CEILING - 1; $i++) {
        resetClient($this);
        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'login-target@example.com',
            'password' => 'not-the-password',
        ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    // Had the failed logins counted, the ceiling would already be reached here.
    for ($i = 0; $i < REJECTED_CEILING; $i++) {
        withForgedToken($this)->getJson(PROTECTED_ROUTE)->assertStatus(401);
    }

    withForgedToken($this)->getJson(PROTECTED_ROUTE)->assertStatus(429);
});

test('when the counter is unreachable the refusal stands', function (): void {
    $this->mock(RateLimiter::class, function ($mock): void {
        $mock->shouldReceive('tooManyAttempts')->andThrow(new RuntimeException('redis is down'));
        $mock->shouldIgnoreMissing();
    });

    // Refused, and refused as itself: not a 500, not a 429 invented without a count,
    // and certainly not admitted.
    withForgedToken($this)->getJson(PROTECTED_ROUTE)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});
