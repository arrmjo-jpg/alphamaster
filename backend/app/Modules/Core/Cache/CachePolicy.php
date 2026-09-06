<?php

declare(strict_types=1);

namespace App\Modules\Core\Cache;

/**
 * How one namespace's entries behave: lifetime, failure, and shape version.
 *
 * Declared once beside the namespace rather than passed at each call site. Two
 * callers caching the same resource cannot then disagree about how long it lives,
 * which is how a value comes to expire at two different times depending on who
 * asked for it (ADR 0035).
 */
final readonly class CachePolicy
{
    /**
     * @param  int  $ttl  seconds an entry survives
     * @param  CacheFailureMode  $failureMode  what happens when the store is unreachable
     * @param  int  $version  bumped in code when the cached *shape* changes, so a
     *                        deployment cannot read last release's structure back
     */
    public function __construct(
        public int $ttl,
        public CacheFailureMode $failureMode = CacheFailureMode::FailOpen,
        public int $version = 1,
    ) {}

    public function failsOpen(): bool
    {
        return $this->failureMode === CacheFailureMode::FailOpen;
    }
}
