<?php

declare(strict_types=1);

namespace App\Modules\Core\Cache;

use App\Modules\Core\Contracts\PlatformCacheContract;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The one way the platform reaches its cache (ADR 0035).
 *
 * Callers name a namespace and a resource; they never compose a key and never pass
 * a TTL. What that buys is not tidiness — it is that invalidation can be reasoned
 * about, that a discriminator has to be justified, and that failure behaviour is a
 * property of the data rather than of whoever happened to write the call.
 *
 * There is deliberately no method that takes a raw key, and none that flushes the
 * store. On a shared Redis a flush empties the whole logical database including
 * entries this platform did not write — Phase 15 demonstrated that when a test run
 * destroyed the development cache.
 */
class PlatformCache implements PlatformCacheContract
{
    /** Generations are read far more often than they change. */
    private const GENERATION_TTL = 604800;

    public function __construct(private readonly CacheKeyBuilder $keys) {}

    /**
     * Read through the cache, computing and storing on a miss.
     *
     * A fail-open namespace whose store is unreachable computes the value and
     * returns it; a fail-closed namespace lets the failure surface, because there
     * the cache is the source of truth and an absence would be read as an answer.
     *
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function remember(
        CacheNamespace $namespace,
        string $resource,
        array $discriminators,
        Closure $callback,
    ): mixed {
        $policy = $namespace->policy();

        try {
            $key = $this->key($namespace, $resource, $discriminators);

            return $this->store()->remember($key, $policy->ttl, $callback);
        } catch (Throwable $e) {
            if (! $policy->failsOpen()) {
                throw $e;
            }

            // Degraded, not broken: the source is still there. Never a security
            // downgrade — a fail-closed namespace has already re-thrown above.
            return $callback();
        }
    }

    /**
     * Read a stored value, or null when absent or unreadable.
     *
     * Distinguishing "cached null" from "not cached" is a caller's concern —
     * LocaleResolver does it with an explicit miss sentinel, because a platform with
     * no active languages is a legitimate state that would otherwise be re-queried
     * on every request.
     *
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function get(CacheNamespace $namespace, string $resource, array $discriminators = [], mixed $default = null): mixed
    {
        try {
            return $this->store()->get($this->key($namespace, $resource, $discriminators), $default);
        } catch (Throwable $e) {
            if (! $namespace->policy()->failsOpen()) {
                throw $e;
            }

            return $default;
        }
    }

    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function put(CacheNamespace $namespace, string $resource, array $discriminators, mixed $value, ?int $ttl = null): void
    {
        try {
            $this->store()->put(
                $this->key($namespace, $resource, $discriminators),
                $value,
                $ttl ?? $namespace->policy()->ttl,
            );
        } catch (Throwable $e) {
            if (! $namespace->policy()->failsOpen()) {
                throw $e;
            }
        }
    }

    /**
     * Forget one entry.
     *
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function forget(CacheNamespace $namespace, string $resource, array $discriminators = []): void
    {
        try {
            $this->store()->forget($this->key($namespace, $resource, $discriminators));
        } catch (Throwable $e) {
            if (! $namespace->policy()->failsOpen()) {
                throw $e;
            }
        }
    }

    /**
     * Invalidate an entire namespace, and nothing outside it.
     *
     * Bumping the generation makes every existing key in this namespace unreachable
     * at once: the old entries are never read again and expire on their own TTL.
     * This is what replaces a flush — it is scoped by construction, it cannot reach
     * another namespace, and it cannot touch a key the platform did not write.
     */
    public function flushNamespace(CacheNamespace $namespace): void
    {
        try {
            $key = $this->keys->generationKey($namespace);
            $store = $this->store();

            $next = $this->asGeneration($store->get($key)) + 1;

            $store->put($key, $next, self::GENERATION_TTL);
        } catch (Throwable $e) {
            if (! $namespace->policy()->failsOpen()) {
                throw $e;
            }
        }
    }

    /**
     * The namespace's current generation.
     *
     * A store that cannot answer yields 0 rather than raising: a fail-open read
     * whose generation is unknown should still produce a usable key.
     */
    public function generation(CacheNamespace $namespace): int
    {
        try {
            return $this->asGeneration($this->store()->get($this->keys->generationKey($namespace)));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function key(CacheNamespace $namespace, string $resource, array $discriminators = []): string
    {
        return $this->keys->build($namespace, $resource, $discriminators, $this->generation($namespace));
    }

    /**
     * Read a stored generation counter.
     *
     * Numeric rather than integer, because a cache store is not obliged to return the
     * type it was given: Redis hands back `'5'` for an integer 5, and an `is_int()`
     * check against that silently reports generation zero — which would leave
     * namespace invalidation looking correct while doing nothing at all.
     */
    private function asGeneration(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function store(): Repository
    {
        return Cache::store();
    }
}
