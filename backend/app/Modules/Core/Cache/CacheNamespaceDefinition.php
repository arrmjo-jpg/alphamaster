<?php

declare(strict_types=1);

namespace App\Modules\Core\Cache;

/**
 * A cache namespace, as any module may declare one (ADR 0035, as extended by ADR 0052).
 *
 * The platform's own namespaces are the cases of `CacheNamespace`. A module that owns
 * cached data of its own — a future content module, say — declares its namespaces as an
 * enum implementing this interface and registers them with `CacheNamespaceRegistry`.
 * Nothing in Core has to be edited for it, and nothing in Core learns what the module is.
 *
 * Declared as an object rather than passed as a string for the reason ADR 0035 gives:
 * a typo must not become a second, permanently cold namespace. The registry is what
 * enforces that now that the declarations no longer live in one enum.
 */
interface CacheNamespaceDefinition
{
    /**
     * The identifier, used as the first segment of every key in the namespace.
     *
     * Lower case, starting with a letter: `settings`, `media`, `competitions`.
     */
    public function namespace(): string;

    /**
     * Lifetime, failure behaviour and shape version, declared once for the namespace.
     */
    public function policy(): CachePolicy;

    /**
     * Whether an operator may invalidate the whole namespace from the Admin.
     *
     * False where the cache is the source of truth for something in flight, or where a
     * package owns the entries and manages them itself.
     */
    public function flushable(): bool;
}
