<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Cache\CacheNamespace;
use Closure;

/**
 * The platform's cache, as its consumers see it (ADR 0035).
 *
 * Deliberately narrow. There is no method taking a raw key and none flushing the
 * store: an administrative operation that could empty a shared Redis is an outage
 * with an audit trail nobody wrote, and a raw-key interface is an authorization
 * hole with no meaning to whoever reads it later.
 */
interface PlatformCacheContract
{
    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function remember(CacheNamespace $namespace, string $resource, array $discriminators, Closure $callback): mixed;

    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function get(CacheNamespace $namespace, string $resource, array $discriminators = [], mixed $default = null): mixed;

    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function put(CacheNamespace $namespace, string $resource, array $discriminators, mixed $value, ?int $ttl = null): void;

    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function forget(CacheNamespace $namespace, string $resource, array $discriminators = []): void;

    /**
     * Invalidate one namespace entirely, reaching nothing outside it.
     */
    public function flushNamespace(CacheNamespace $namespace): void;

    public function generation(CacheNamespace $namespace): int;

    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function key(CacheNamespace $namespace, string $resource, array $discriminators = []): string;
}
