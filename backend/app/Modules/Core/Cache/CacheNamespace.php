<?php

declare(strict_types=1);

namespace App\Modules\Core\Cache;

/**
 * The owning domain of a cache entry (ADR 0035).
 *
 * One namespace per owner, so invalidation can be scoped to a domain without
 * knowing that domain's keys. A namespace is declared here rather than passed as a
 * string, which is what stops a typo becoming a second, permanently cold namespace.
 */
enum CacheNamespace: string
{
    case SETTINGS = 'settings';
    case LOCALIZATION = 'localization';
    case AUTH = 'auth';
    case AUTHORIZATION = 'authorization';

    /**
     * This namespace's lifetime, failure behaviour and shape version.
     *
     * The MFA challenge is the platform's only fail-closed namespace: the cache is
     * the source of truth for whether a challenge was issued, so a store it cannot
     * reach must not read as "no challenge outstanding".
     */
    public function policy(): CachePolicy
    {
        return match ($this) {
            self::SETTINGS => new CachePolicy(ttl: 86400),
            self::LOCALIZATION => new CachePolicy(ttl: 86400),
            self::AUTH => new CachePolicy(ttl: 900, failureMode: CacheFailureMode::FailClosed),
            // Vendor-managed: Spatie writes and reads this cache itself and knows its
            // own store. The platform never composes its key — it resets it through the
            // package's registrar — so this policy describes the namespace for
            // completeness rather than governing entries the platform writes.
            self::AUTHORIZATION => new CachePolicy(ttl: 86400),
        };
    }
}
