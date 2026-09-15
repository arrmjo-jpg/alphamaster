<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Cache\CacheNamespaceDefinition;
use Closure;

/**
 * The platform's cache, as its consumers see it (ADR 0035).
 *
 * Deliberately narrow. There is no method taking a raw key and none flushing the
 * store: an administrative operation that could empty a shared Redis is an outage
 * with an audit trail nobody wrote, and a raw-key interface is an authorization
 * hole with no meaning to whoever reads it later.
 *
 * Every method takes a declared namespace — the platform's own `CacheNamespace`, or one
 * a module registered (ADR 0052). A namespace nobody registered is refused.
 */
interface PlatformCacheContract
{
    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function remember(CacheNamespaceDefinition $namespace, string $resource, array $discriminators, Closure $callback): mixed;

    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function get(CacheNamespaceDefinition $namespace, string $resource, array $discriminators = [], mixed $default = null): mixed;

    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function put(CacheNamespaceDefinition $namespace, string $resource, array $discriminators, mixed $value, ?int $ttl = null): void;

    /**
     * Store a value only if nothing is stored under the key, and answer whether this
     * call is the one that stored it.
     *
     * Atomic on the stores the platform runs (Redis sets it with NX), which is what makes
     * it usable to consume something exactly once: two requests racing to add the same
     * key cannot both be told they won. A fail-open namespace that cannot reach its store
     * answers false — this call could not prove it was first — and a fail-closed one
     * lets the failure surface.
     *
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function add(CacheNamespaceDefinition $namespace, string $resource, array $discriminators, mixed $value, ?int $ttl = null): bool;

    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function forget(CacheNamespaceDefinition $namespace, string $resource, array $discriminators = []): void;

    /**
     * Invalidate one namespace entirely, reaching nothing outside it.
     */
    public function flushNamespace(CacheNamespaceDefinition $namespace): void;

    public function generation(CacheNamespaceDefinition $namespace): int;

    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function key(CacheNamespaceDefinition $namespace, string $resource, array $discriminators = []): string;
}
