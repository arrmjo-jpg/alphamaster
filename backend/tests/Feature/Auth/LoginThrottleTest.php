<?php

declare(strict_types=1);

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where rate-limiter counters outlive a test.
    Cache::flush();

    $this->seed(SettingSeeder::class);

    User::create([
        'name' => 'Throttle Target',
        'email' => 'target@example.com',
        'password' => 'the-real-password',
        'is_admin' => false,
        'is_active' => true,
    ]);
});

/**
 * @return TestResponse
 */
function attemptLogin(mixed $test, string $password = 'wrong', string $email = 'target@example.com')
{
    return $test->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => $password,
    ]);
}

test('the throttle blocks further attempts once the configured maximum is reached', function (): void {
    $max = setting('security.max_login_attempts');
    expect($max)->toBe(5); // seeded default, guards the rest of this test

    // Exhaust exactly the allowance; each is a genuine 401, not a 429.
    for ($i = 0; $i < $max; $i++) {
        attemptLogin($this)->assertStatus(401);
    }

    // The next attempt is refused by the limiter, before credentials are examined.
    $blocked = attemptLogin($this);

    $blocked->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');

    expect($blocked->headers->get('Retry-After'))->not->toBeNull()
        ->and((int) $blocked->json('error.details.retry_after'))->toBeGreaterThan(0);
});

test('the throttle blocks the correct password too, so it is a real lockout', function (): void {
    $max = setting('security.max_login_attempts');

    for ($i = 0; $i < $max; $i++) {
        attemptLogin($this)->assertStatus(401);
    }

    // This is the decisive assertion: if the limiter only rejected bad passwords it
    // would be theatre. A valid credential must also be refused while locked out.
    attemptLogin($this, 'the-real-password')->assertStatus(429);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('the lockout window is finite and sized by the configured decay', function (): void {
    // The expiry itself is not driven forward with travel(): Carbon's fake clock does
    // not move a Redis TTL, so that technique passes on the array store and fails on
    // the real one. What belongs to this module is the window it asks for, which is
    // asserted directly; actually retiring the key is the cache's job.
    $max = setting('security.max_login_attempts');
    $decaySeconds = setting('security.decay_minutes') * 60;

    for ($i = 0; $i < $max; $i++) {
        attemptLogin($this)->assertStatus(401);
    }

    $blocked = attemptLogin($this, 'the-real-password')->assertStatus(429);
    $retryAfter = (int) $blocked->json('error.details.retry_after');

    expect($retryAfter)->toBeGreaterThan(0)
        ->and($retryAfter)->toBeLessThanOrEqual($decaySeconds);
});

test('the decay window is read from Settings, not hardcoded', function (): void {
    app(SettingServiceInterface::class)->updateGroup('security', [
        'max_login_attempts' => 1,
        'decay_minutes' => 30,
    ]);

    attemptLogin($this)->assertStatus(401);
    $blocked = attemptLogin($this, 'the-real-password')->assertStatus(429);

    // A 30 minute lockout cannot be produced by the 1 minute seeded default or by
    // the class fallback, so the value must have come from Settings.
    expect((int) $blocked->json('error.details.retry_after'))
        ->toBeGreaterThan(29 * 60)
        ->and((int) $blocked->json('error.details.retry_after'))->toBeLessThanOrEqual(30 * 60);
});

test('clearing the limiter restores access immediately', function (): void {
    $max = setting('security.max_login_attempts');

    for ($i = 0; $i < $max; $i++) {
        attemptLogin($this)->assertStatus(401);
    }

    attemptLogin($this, 'the-real-password')->assertStatus(429);

    // Proves the block is limiter state rather than anything sticky on the account.
    Cache::flush();

    attemptLogin($this, 'the-real-password')->assertOk();
});

test('a successful sign-in clears the counter', function (): void {
    attemptLogin($this)->assertStatus(401);
    attemptLogin($this)->assertStatus(401);

    attemptLogin($this, 'the-real-password')->assertOk();

    // Counter reset: the full allowance is available again.
    $max = setting('security.max_login_attempts');
    for ($i = 0; $i < $max; $i++) {
        attemptLogin($this)->assertStatus(401);
    }
    attemptLogin($this)->assertStatus(429);
});

test('the throttle honours limits changed at runtime through Settings', function (): void {
    // Proves the limit is read from Settings rather than hardcoded.
    app(SettingServiceInterface::class)->updateGroup('security', [
        'max_login_attempts' => 2,
    ]);

    attemptLogin($this)->assertStatus(401);
    attemptLogin($this)->assertStatus(401);
    attemptLogin($this)->assertStatus(429);
});

test('the throttle is scoped per account, not global', function (): void {
    User::create([
        'name' => 'Bystander',
        'email' => 'bystander@example.com',
        'password' => 'bystander-password',
        'is_admin' => false,
        'is_active' => true,
    ]);

    $max = setting('security.max_login_attempts');
    for ($i = 0; $i < $max + 1; $i++) {
        attemptLogin($this);
    }

    attemptLogin($this, 'wrong')->assertStatus(429);

    // A different account from the same address is unaffected: one locked account
    // must not become a denial of service against everyone else.
    attemptLogin($this, 'bystander-password', 'bystander@example.com')->assertOk();
});

test('failed attempts report how many remain', function (): void {
    $first = attemptLogin($this);

    expect($first->json('error.details.attempts_remaining'))
        ->toBe(setting('security.max_login_attempts') - 1);
});

test('a suspended account still consumes throttle attempts', function (): void {
    User::create([
        'name' => 'Suspended',
        'email' => 'locked@example.com',
        'password' => 'locked-password',
        'is_admin' => false,
        'is_active' => false,
    ]);

    $max = setting('security.max_login_attempts');

    for ($i = 0; $i < $max; $i++) {
        attemptLogin($this, 'locked-password', 'locked@example.com')->assertStatus(403);
    }

    // Otherwise the endpoint would be an unlimited oracle for which accounts exist.
    attemptLogin($this, 'locked-password', 'locked@example.com')->assertStatus(429);
});
