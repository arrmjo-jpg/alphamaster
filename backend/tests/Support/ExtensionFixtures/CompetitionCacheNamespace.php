<?php

declare(strict_types=1);

namespace Tests\Support\ExtensionFixtures;

use App\Modules\Core\Cache\CacheNamespaceDefinition;
use App\Modules\Core\Cache\CachePolicy;

/**
 * A cache namespace as a future domain module would declare it (ADR 0052).
 *
 * Lives in the test suite, not in the application: the platform ships no domain module,
 * and this exists only to prove one could be added without editing Core.
 */
enum CompetitionCacheNamespace: string implements CacheNamespaceDefinition
{
    case STANDINGS = 'competitions';

    case LIVE_SCORES = 'competition_live';

    public function namespace(): string
    {
        return $this->value;
    }

    public function policy(): CachePolicy
    {
        return match ($this) {
            self::STANDINGS => new CachePolicy(ttl: 600),
            self::LIVE_SCORES => new CachePolicy(ttl: 15),
        };
    }

    public function flushable(): bool
    {
        return $this !== self::LIVE_SCORES;
    }
}
