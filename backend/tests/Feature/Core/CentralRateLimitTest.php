<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Middleware\ApplyRateLimit;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\Core\Services\RateLimitPolicy;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter as Limiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a counter outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    $this->settings = app(SettingServiceInterface::class);
});

/**
 * Lower a class ceiling so exhausting it takes a handful of requests instead of
 * a hundred. Doubles as proof that the limits are read from Settings.
 */
function limitClassTo(mixed $test, string $setting, int $value): void
{
    $test->settings->set('rate_limit', $setting, $value);
}

/**
 * Send the same request repeatedly and return the first non-200 response.
 */
function exhaust(mixed $test, string $method, string $uri, array $headers = [], int $tries = 12): TestResponse
{
    $last = null;

    for ($i = 0; $i < $tries; $i++) {
        resetClient($test);
        $last = $test->withHeaders($headers)->json($method, $uri);

        if ($last->status() === 429) {
            break;
        }
    }

    return $last;
}

// ── The health exemption ─────────────────────────────────────────────────────

test('the health endpoint is never limited', function (): void {
    limitClassTo($this, 'public_read_per_minute', 1);

    for ($i = 0; $i < 5; $i++) {
        resetClient($this);
        $response = $this->getJson('/api/v1/health');

        expect($response->status())->toBe(200, 'health was limited on request '.($i + 1));
    }

    expect($response->headers->has('X-RateLimit-Limit'))->toBeFalse();
});

// ── Anonymous: IP + class ────────────────────────────────────────────────────

test('an anonymous read is limited per IP and reports the contract', function (): void {
    limitClassTo($this, 'public_read_per_minute', 2);

    $blocked = exhaust($this, 'GET', '/api/v1/languages');

    expect($blocked->status())->toBe(429)
        ->and($blocked->json('error.code'))->toBe('TOO_MANY_ATTEMPTS')
        ->and($blocked->json('error.details.retry_after'))->toBeGreaterThan(0)
        ->and($blocked->headers->get('Retry-After'))->not->toBeNull()
        ->and($blocked->headers->get('X-RateLimit-Limit'))->toBe('2')
        ->and($blocked->headers->get('X-RateLimit-Remaining'))->toBe('0');
});

test('the anonymous rejection is localized', function (): void {
    limitClassTo($this, 'public_read_per_minute', 1);

    $blocked = exhaust($this, 'GET', '/api/v1/settings', ['X-Locale' => 'ar']);

    expect($blocked->status())->toBe(429)
        ->and($blocked->json('error.message'))->toContain('عدد الطلبات كبير')
        ->and($blocked->json('error.code'))->toBe('TOO_MANY_ATTEMPTS');
});

test('a successful anonymous request carries the remaining allowance', function (): void {
    limitClassTo($this, 'public_read_per_minute', 10);

    $response = $this->getJson('/api/v1/languages');

    expect($response->status())->toBe(200)
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('10')
        ->and((int) $response->headers->get('X-RateLimit-Remaining'))->toBe(9);
});

// ── Authenticated: user + class, with IP as a second dimension ───────────────

test('an authenticated read is limited per user', function (): void {
    limitClassTo($this, 'read_per_minute', 2);

    $token = regularWithToken($this, 'reader@example.test')['token'];
    $blocked = exhaust($this, 'GET', '/api/v1/notifications/preferences', ['Authorization' => 'Bearer '.$token]);

    expect($blocked->status())->toBe(429)
        ->and($blocked->json('error.code'))->toBe('TOO_MANY_ATTEMPTS')
        ->and($blocked->headers->get('X-RateLimit-Limit'))->toBe('2');
});

test('two users do not share an allowance', function (): void {
    // The identity dimension is the user, so one exhausting their budget must
    // not lock out the other — even though both arrive from the same address.
    limitClassTo($this, 'read_per_minute', 2);

    $first = regularWithToken($this, 'first@example.test')['token'];
    $second = regularWithToken($this, 'second@example.test')['token'];

    $blocked = exhaust($this, 'GET', '/api/v1/notifications/preferences', ['Authorization' => 'Bearer '.$first]);
    expect($blocked->status())->toBe(429);

    resetClient($this);
    $other = $this->withHeaders(['Authorization' => 'Bearer '.$second])
        ->getJson('/api/v1/notifications/preferences');

    expect($other->status())->toBe(200, 'the second user was blocked by the first');
});

test('the per-IP ceiling is looser than the per-user one', function (): void {
    // Shared NAT: many users behind one address. With the multiplier at 4 the
    // address allows four times what one user does, so the second colleague is
    // not refused because the first was busy.
    limitClassTo($this, 'read_per_minute', 2);
    limitClassTo($this, 'authenticated_ip_multiplier', 4);

    $policy = app(RateLimitPolicy::class);

    expect($policy->maxAttempts('read'))->toBe(2)
        ->and($policy->maxAttemptsForIp('read'))->toBe(8);
});

// ── Classes do not compete ───────────────────────────────────────────────────

test('exhausting reads does not consume the write budget', function (): void {
    limitClassTo($this, 'read_per_minute', 2);
    limitClassTo($this, 'write_per_minute', 10);

    $token = regularWithToken($this, 'mixed@example.test')['token'];
    $headers = ['Authorization' => 'Bearer '.$token];

    expect(exhaust($this, 'GET', '/api/v1/notifications/preferences', $headers)->status())->toBe(429);

    resetClient($this);
    $write = $this->withHeaders($headers)->putJson('/api/v1/notifications/preferences', [
        'preferences' => [
            ['type' => 'security.alert', 'channel' => 'mail', 'enabled' => true],
        ],
    ]);

    expect($write->status())->not->toBe(429, 'a read burst exhausted the write budget');
});

test('an upload has its own budget, separate from writes', function (): void {
    $policy = app(RateLimitPolicy::class);

    expect($policy->maxAttempts('upload'))->toBe(20)
        ->and($policy->decayMinutes('upload'))->toBe(60)
        ->and($policy->decayMinutes('write'))->toBe(1);
});

// ── Settings drive it at runtime ─────────────────────────────────────────────

test('a limit changed through Settings binds on the next request', function (): void {
    limitClassTo($this, 'public_read_per_minute', 50);

    expect((int) $this->getJson('/api/v1/languages')->headers->get('X-RateLimit-Limit'))->toBe(50);

    limitClassTo($this, 'public_read_per_minute', 7);
    resetClient($this);

    expect((int) $this->getJson('/api/v1/languages')->headers->get('X-RateLimit-Limit'))->toBe(7);
});

// ── The existing auth throttles stay authoritative ───────────────────────────

test('the login throttle still binds before the class ceiling', function (): void {
    // LoginThrottle allows five attempts; the auth class allows sixty. The
    // tighter one must be what a caller meets.
    makeAccount(['email' => 'throttled@example.test']);

    $last = null;

    for ($i = 0; $i < 8; $i++) {
        resetClient($this);
        $last = $this->postJson('/api/v1/auth/login', [
            'email' => 'throttled@example.test',
            'password' => 'wrong-password',
        ]);

        if ($last->status() === 429) {
            break;
        }
    }

    expect($last->status())->toBe(429)
        ->and($last->json('error.code'))->toBe('TOO_MANY_ATTEMPTS')
        // LoginThrottle reports its own retry_after and never reaches the
        // renderer, so a rejection here is still the auth throttle's.
        ->and($last->json('error.details.retry_after'))->toBeGreaterThan(0)
        ->and($i + 1)->toBeLessThanOrEqual(6, 'the class ceiling bound before LoginThrottle did');
});

test('the auth endpoints are still reachable under the class ceiling', function (): void {
    // The central limiter must not refuse a first login attempt.
    makeAccount(['email' => 'reachable@example.test']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'reachable@example.test',
        'password' => TEST_ACCOUNT_PASSWORD,
    ]);

    expect($response->status())->toBe(200);
});

// ── Redis failure fails open ─────────────────────────────────────────────────

/**
 * Run a callback with Redis unreachable.
 *
 * The failure is induced by pointing the real connection at a host that does
 * not resolve, rather than by substituting a double, so the repository's own
 * Redis abstraction raises exactly as it would in an outage. Every resolved
 * instance that holds a connection is forgotten so it is rebuilt against the
 * broken one.
 */
function withRedisUnavailable(callable $callback): mixed
{
    // The cache store does not use the `default` Redis connection; it uses the one
    // named in cache.stores.<store>.connection, which is `cache`. Breaking the
    // wrong connection leaves the limiter working and proves nothing.
    $connection = config('cache.stores.'.config('cache.default').'.connection', 'cache');
    $hostKey = 'database.redis.'.$connection.'.host';
    $originalHost = config($hostKey);

    $rebuild = function (): void {
        foreach (['redis', 'redis.connection', 'cache', 'cache.store', RateLimiter::class] as $binding) {
            app()->forgetInstance($binding);
        }

        Redis::clearResolvedInstances();
        Cache::clearResolvedInstances();
        Limiter::clearResolvedInstances();

        // A rebuilt limiter singleton has no definitions: the provider booted
        // once and will not boot again. Without this the middleware would find no
        // named limiter and the test would prove nothing about Redis.
        app()->getProvider(CoreServiceProvider::class)->registerRateLimiters();
    };

    config([$hostKey => 'no-such-redis-host']);
    $rebuild();

    try {
        return $callback();
    } finally {
        config([$hostKey => $originalHost]);
        $rebuild();
    }
}

test('the limiter itself fails open when Redis is unreachable', function (): void {
    // Exercised at the middleware, because that is the unit this phase owns. The
    // Redis failure is real — the connection the cache store uses is pointed at a
    // host that does not resolve — so the limiter's own counting genuinely raises.
    $request = Request::create('/api/v1/languages', 'GET');
    $request->setRouteResolver(fn () => Route::getRoutes()->getByName('api.languages.index'));

    $reached = false;

    $response = withRedisUnavailable(function () use ($request, &$reached) {
        $middleware = new ApplyRateLimit(app(RateLimiter::class));

        return $middleware->handle($request, function () use (&$reached) {
            $reached = true;

            return new Response('downstream reached');
        });
    });

    expect($reached)->toBeTrue('the limiter swallowed the request instead of passing it on')
        ->and($response->getContent())->toBe('downstream reached')
        ->and($response->headers->has('X-RateLimit-Limit'))->toBeFalse();
});

test('a real rejection is never mistaken for an outage', function (): void {
    // The catch must not swallow a genuine ThrottleRequestsException, or a caller
    // over the limit would be served instead of refused.
    limitClassTo($this, 'public_read_per_minute', 1);

    $blocked = exhaust($this, 'GET', '/api/v1/languages');

    expect($blocked->status())->toBe(429)
        ->and($blocked->json('error.code'))->toBe('TOO_MANY_ATTEMPTS');
});

test('the limiter is not what takes the API down when Redis is unreachable', function (): void {
    // Recorded rather than hidden. With Redis unreachable the whole API returns
    // 500 today, and the limiter is not the cause: /api/v1/health is exempt from
    // it and fails identically. SetLocale runs globally and resolves the locale
    // through the cache, and setting() reads through it too — both raise. Making
    // the platform survive a cache outage end to end is a separate concern from
    // this phase, which owns only the limiter's own behaviour.
    $exempt = withRedisUnavailable(fn () => $this->getJson('/api/v1/health'));

    expect($exempt->status())->toBe(500, 'the platform-wide cache dependency has changed; revisit this test');
});
