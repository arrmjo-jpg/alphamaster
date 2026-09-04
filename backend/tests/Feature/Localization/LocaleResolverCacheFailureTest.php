<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Services\LocaleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->resolver = app(LocaleResolverInterface::class);
});

/**
 * Run a callback while the database is unreachable.
 *
 * The failure is induced at the connection rather than with a broken query, for
 * a reason worth recording: PostgreSQL aborts the entire transaction once a
 * statement fails, and RefreshDatabase wraps each test in one, so a real SQL
 * error leaves nothing else runnable and recovery cannot be observed. Pointing
 * the default connection at an unconfigured driver raises before any SQL is
 * sent, which is also the shape of the failure this fix is about — the database
 * being unavailable, not a malformed query.
 */
function withDatabaseUnavailable(callable $callback): mixed
{
    $original = config('database.default');

    config(['database.connections.unreachable' => ['driver' => 'no-such-driver']]);
    config(['database.default' => 'unreachable']);

    try {
        return $callback();
    } finally {
        config(['database.default' => $original]);
    }
}

// ── The defect ────────────────────────────────────────────────────────────────

test('a failed read is never written to the cache', function (): void {
    expect(Cache::has(LocaleResolver::CACHE_KEY_ACTIVE))->toBeFalse();

    $languages = withDatabaseUnavailable(fn () => $this->resolver->getActiveLanguages());

    expect($languages)->toBeEmpty()
        ->and(Cache::has(LocaleResolver::CACHE_KEY_ACTIVE))->toBeFalse(
            'a query that raised must not leave a cached result behind'
        );
});

test('the default-language read was never poisoned, and is left alone', function (): void {
    // Only getActiveLanguages needed fixing. Cache::remember re-runs its closure
    // when the cached value is null, so a failed default read is retried on the
    // next request instead of being held for the TTL — verified rather than
    // assumed, which is why that method is untouched by this change.
    $duringFailure = withDatabaseUnavailable(fn () => $this->resolver->getDefaultLanguageCode());

    expect($duringFailure)->toBeNull()
        ->and($this->resolver->getDefaultLanguageCode())->toBe('en');
});

test('a later successful read recovers the active languages', function (): void {
    // The whole point: a transient failure must not decide the next 24 hours.
    withDatabaseUnavailable(fn () => $this->resolver->getActiveLanguages());

    $codes = $this->resolver->getActiveLanguageCodes();

    expect($codes)->toContain('en')
        ->and($codes)->toContain('ar')
        ->and(Cache::has(LocaleResolver::CACHE_KEY_ACTIVE))->toBeTrue();
});

test('a failure does not degrade locale negotiation beyond the request it happened in', function (): void {
    // This is what the defect actually cost: X-Locale: ar answered as en, for a
    // day, on a platform with ar active.
    $request = Request::create('/api/v1/health');
    $request->headers->set('X-Locale', 'ar');

    $duringFailure = withDatabaseUnavailable(fn () => $this->resolver->resolve($request));
    $afterRecovery = $this->resolver->resolve($request);

    expect($duringFailure)->toBe(config('app.locale'))
        ->and($afterRecovery)->toBe('ar');
});

// ── The behaviour that must not change ───────────────────────────────────────

test('a successful read still caches, and the second read does not query again', function (): void {
    $first = $this->resolver->getActiveLanguages();

    expect(Cache::has(LocaleResolver::CACHE_KEY_ACTIVE))->toBeTrue();

    // With the database unreachable, a second call can only come from the cache.
    $second = withDatabaseUnavailable(fn () => $this->resolver->getActiveLanguages());

    expect($second->pluck('code')->all())->toBe($first->pluck('code')->all())
        ->and($second)->not->toBeEmpty();
});

test('an empty result is a legitimate answer and is still cached', function (): void {
    // No active language is a real state, distinct from a read that failed, and
    // it must not be re-queried on every request.
    Cache::flush();
    Language::query()->update(['is_active' => false]);

    expect($this->resolver->getActiveLanguages())->toBeEmpty()
        ->and(Cache::has(LocaleResolver::CACHE_KEY_ACTIVE))->toBeTrue()
        ->and(Cache::get(LocaleResolver::CACHE_KEY_ACTIVE))->toBe([]);
});

test('the resolution precedence is unchanged', function (): void {
    $explicit = Request::create('/api/v1/health');
    $explicit->headers->set('X-Locale', 'ar');

    $accept = Request::create('/api/v1/health');
    $accept->headers->set('Accept-Language', 'ar;q=0.9,en;q=0.1');

    $neither = Request::create('/api/v1/health');

    expect($this->resolver->resolve($explicit))->toBe('ar')
        ->and($this->resolver->resolve($accept))->toBe('ar')
        ->and($this->resolver->resolve($neither))->toBe('en')
        ->and($this->resolver->getDefaultLocale())->toBe('en');
});

test('an inactive locale is refused and falls through the precedence', function (): void {
    $request = Request::create('/api/v1/health');
    $request->headers->set('X-Locale', 'fr');

    expect($this->resolver->resolve($request))->toBe('en');
});

test('direction still resolves from the database record', function (): void {
    expect($this->resolver->getDirection('ar'))->toBe('rtl')
        ->and($this->resolver->getDirection('en'))->toBe('ltr')
        ->and($this->resolver->getDirection('fr'))->toBe('ltr');
});

test('clearing the cache still empties both keys', function (): void {
    $this->resolver->getActiveLanguages();
    $this->resolver->getDefaultLanguageCode();

    expect(Cache::has(LocaleResolver::CACHE_KEY_ACTIVE))->toBeTrue()
        ->and(Cache::has(LocaleResolver::CACHE_KEY_DEFAULT))->toBeTrue();

    $this->resolver->clearCache();

    expect(Cache::has(LocaleResolver::CACHE_KEY_ACTIVE))->toBeFalse()
        ->and(Cache::has(LocaleResolver::CACHE_KEY_DEFAULT))->toBeFalse();
});
