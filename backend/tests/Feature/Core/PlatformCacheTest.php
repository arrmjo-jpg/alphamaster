<?php

declare(strict_types=1);

use App\Modules\Core\Cache\CacheFailureMode;
use App\Modules\Core\Cache\CacheKeyBuilder;
use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\PlatformCacheContract;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    $this->cache = app(PlatformCacheContract::class);
    $this->keys = new CacheKeyBuilder;
});

// ── Keys are built, never written ────────────────────────────────────────────

test('a key carries its namespace, resource, discriminators and version', function (): void {
    $key = $this->keys->build(CacheNamespace::SETTINGS, 'public', ['locale' => 'ar'], 3);

    expect($key)->toBe('settings:public:locale=ar:v1.3');
});

test('the same discriminators produce the same key whatever order they are given', function (): void {
    // Two call sites caching the same thing must not produce two entries.
    $a = $this->keys->build(CacheNamespace::SETTINGS, 'group_index', ['group' => 'general', 'locale' => 'en']);
    $b = $this->keys->build(CacheNamespace::SETTINGS, 'group_index', ['locale' => 'en', 'group' => 'general']);

    expect($a)->toBe($b);
});

test('a null discriminator cannot collide with an empty one', function (): void {
    $null = $this->keys->build(CacheNamespace::SETTINGS, 'public', ['locale' => null]);
    $empty = $this->keys->build(CacheNamespace::SETTINGS, 'public', ['locale' => '']);

    expect($null)->not->toBe($empty);
});

test('a resource is an identifier, not free text', function (): void {
    // An unvalidated resource segment is how an attacker-supplied value mints a
    // cache entry of its own.
    expect(fn () => $this->keys->build(CacheNamespace::SETTINGS, 'a resource with spaces'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->keys->build(CacheNamespace::SETTINGS, str_repeat('x', 101)))
        ->toThrow(InvalidArgumentException::class);
});

// ── Reading and writing ──────────────────────────────────────────────────────

test('remember stores on a miss and returns the stored value after', function (): void {
    $calls = 0;

    $produce = function () use (&$calls): string {
        $calls++;

        return 'computed';
    };

    expect($this->cache->remember(CacheNamespace::SETTINGS, 'probe', [], $produce))->toBe('computed')
        ->and($this->cache->remember(CacheNamespace::SETTINGS, 'probe', [], $produce))->toBe('computed')
        ->and($calls)->toBe(1);
});

test('an entry is reachable only with the discriminators it was written under', function (): void {
    $this->cache->put(CacheNamespace::SETTINGS, 'probe', ['locale' => 'en'], 'english');

    expect($this->cache->get(CacheNamespace::SETTINGS, 'probe', ['locale' => 'en']))->toBe('english')
        ->and($this->cache->get(CacheNamespace::SETTINGS, 'probe', ['locale' => 'ar']))->toBeNull();
});

test('forget removes one entry and leaves its neighbours', function (): void {
    $this->cache->put(CacheNamespace::SETTINGS, 'probe', ['locale' => 'en'], 'english');
    $this->cache->put(CacheNamespace::SETTINGS, 'probe', ['locale' => 'ar'], 'arabic');

    $this->cache->forget(CacheNamespace::SETTINGS, 'probe', ['locale' => 'en']);

    expect($this->cache->get(CacheNamespace::SETTINGS, 'probe', ['locale' => 'en']))->toBeNull()
        ->and($this->cache->get(CacheNamespace::SETTINGS, 'probe', ['locale' => 'ar']))->toBe('arabic');
});

// ── Namespace invalidation, which replaces flushing ──────────────────────────

test('flushing a namespace makes its entries unreachable', function (): void {
    $this->cache->put(CacheNamespace::SETTINGS, 'probe', [], 'value');

    $this->cache->flushNamespace(CacheNamespace::SETTINGS);

    expect($this->cache->get(CacheNamespace::SETTINGS, 'probe', []))->toBeNull();
});

test('the generation actually advances', function (): void {
    // Regression: the counter comes back from Redis as the string '1', so an is_int()
    // check reported generation zero and namespace invalidation silently did nothing
    // while every test that only asserted a miss still passed.
    $before = $this->cache->generation(CacheNamespace::SETTINGS);

    $this->cache->flushNamespace(CacheNamespace::SETTINGS);

    expect($this->cache->generation(CacheNamespace::SETTINGS))->toBe($before + 1)
        ->and($this->cache->key(CacheNamespace::SETTINGS, 'probe'))
        ->not->toBe($this->keys->build(CacheNamespace::SETTINGS, 'probe', [], $before));
});

test('flushing one namespace does not reach another', function (): void {
    // The property that makes this safe to expose at all: a scoped invalidation can
    // never empty a store it does not own.
    $this->cache->put(CacheNamespace::SETTINGS, 'probe', [], 'settings value');
    $this->cache->put(CacheNamespace::LOCALIZATION, 'probe', [], 'localization value');

    $this->cache->flushNamespace(CacheNamespace::SETTINGS);

    expect($this->cache->get(CacheNamespace::SETTINGS, 'probe', []))->toBeNull()
        ->and($this->cache->get(CacheNamespace::LOCALIZATION, 'probe', []))->toBe('localization value');
});

test('flushing a namespace leaves keys the platform did not write', function (): void {
    // A Redis-wide flush would take these with it. Phase 15 showed what that costs
    // when a test run destroyed the development cache.
    Cache::put('something_else_entirely', 'untouched', 600);

    $this->cache->flushNamespace(CacheNamespace::SETTINGS);

    expect(Cache::get('something_else_entirely'))->toBe('untouched');
});

// ── Failure behaviour is a property of the namespace ─────────────────────────

test('the platform declares exactly one fail-closed namespace', function (): void {
    // Fail-open is right wherever the source of truth is a database that is still
    // there. The MFA challenge is the exception: the cache *is* the record that a
    // challenge was issued, so an unreachable store must not read as "none pending".
    $closed = array_values(array_filter(
        CacheNamespace::cases(),
        static fn (CacheNamespace $n): bool => $n->policy()->failureMode === CacheFailureMode::FailClosed,
    ));

    expect($closed)->toBe([CacheNamespace::AUTH]);
});

test('a fail-open namespace still answers when the store cannot', function (): void {
    // Redis being unavailable must not turn a readable page into an error.
    config(['cache.stores.broken' => ['driver' => 'redis', 'connection' => 'does_not_exist']]);
    config(['cache.default' => 'broken']);

    expect($this->cache->remember(CacheNamespace::SETTINGS, 'probe', [], fn (): string => 'from source'))
        ->toBe('from source');
});

test('a fail-closed namespace surfaces the failure instead of inventing an absence', function (): void {
    config(['cache.stores.broken' => ['driver' => 'redis', 'connection' => 'does_not_exist']]);
    config(['cache.default' => 'broken']);

    // Caught rather than matched on a class: what matters is that the failure
    // reaches the caller at all, not which driver raised it.
    $surfaced = false;

    try {
        $this->cache->remember(CacheNamespace::AUTH, 'probe', [], fn (): string => 'unreachable');
    } catch (Throwable) {
        $surfaced = true;
    }

    expect($surfaced)->toBeTrue('a fail-closed namespace swallowed a store failure');
});

// ── The contract is deliberately narrow ─────────────────────────────────────

test('there is no way to reach a raw key or flush the store', function (): void {
    // A raw-key admin interface is an authorization hole with no audit trail, and a
    // store-wide flush is an outage. Neither is expressible through this contract.
    $methods = get_class_methods(PlatformCacheContract::class);

    expect($methods)->not->toContain('flush')
        ->and($methods)->not->toContain('clear');

    foreach ($methods as $method) {
        $first = (new ReflectionMethod(PlatformCacheContract::class, $method))->getParameters()[0] ?? null;

        expect($first?->getType()?->getName())
            ->toBe(CacheNamespace::class, $method.' does not start from a namespace');
    }
});
