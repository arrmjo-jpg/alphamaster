<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Cache;

use LogicException;

/**
 * Every HTTP cache profile the running platform declares (ADR 0053 §2).
 *
 * Core declares the profiles its own public endpoints use; a module registers the ones its
 * public endpoints need from its own service provider. A route naming a profile nobody
 * registered fails loudly rather than being served with no caching decision at all.
 */
final class HttpCacheProfileRegistry
{
    /** @var array<string, HttpCacheProfile> */
    private array $profiles = [];

    public function register(HttpCacheProfile ...$profiles): void
    {
        foreach ($profiles as $profile) {
            $existing = $this->profiles[$profile->name] ?? null;

            if ($existing !== null && $existing != $profile) {
                throw new LogicException("HTTP cache profile [{$profile->name}] is already declared differently.");
            }

            $this->profiles[$profile->name] = $profile;
        }
    }

    public function find(string $name): ?HttpCacheProfile
    {
        return $this->profiles[$name] ?? null;
    }

    /**
     * @return list<HttpCacheProfile>
     */
    public function all(): array
    {
        return array_values($this->profiles);
    }
}
