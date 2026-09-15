<?php

declare(strict_types=1);

namespace Tests\Support\ExtensionFixtures;

use App\Modules\Core\Cache\CacheNamespaceDefinition;
use App\Modules\Core\Cache\CachePolicy;

/**
 * A module trying to declare a namespace the platform already owns.
 */
enum CollidingCacheNamespace: string implements CacheNamespaceDefinition
{
    case SETTINGS = 'settings';

    public function namespace(): string
    {
        return $this->value;
    }

    public function policy(): CachePolicy
    {
        return new CachePolicy(ttl: 5);
    }

    public function flushable(): bool
    {
        return true;
    }
}
