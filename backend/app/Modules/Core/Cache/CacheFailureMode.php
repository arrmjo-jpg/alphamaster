<?php

declare(strict_types=1);

namespace App\Modules\Core\Cache;

/**
 * What a namespace does when the cache store cannot be reached (ADR 0035).
 */
enum CacheFailureMode
{
    /**
     * Bypass the cache and read the source.
     *
     * The default, and correct wherever the source of truth is a database that is
     * still there. Redis being unavailable must not turn a readable page into an
     * error.
     */
    case FailOpen;

    /**
     * Refuse the operation.
     *
     * Correct only where the cache *is* the source of truth for a security decision.
     * A challenge that cannot be verified must not be treated as satisfied, so the
     * failure surfaces rather than being read as an absence.
     */
    case FailClosed;
}
