<?php

declare(strict_types=1);

namespace App\Modules\Core\Cache;

use LogicException;

/**
 * Every cache namespace the running platform has declared (ADR 0052).
 *
 * Core registers its own; each module registers the namespaces it owns from its service
 * provider. The platform cache refuses a namespace nobody registered, which keeps the
 * guarantee a single enum used to give — no call site can invent a namespace — while
 * letting a module own its declarations.
 *
 * Two different declarations under one identifier are refused rather than merged. They
 * would share keys and generations while disagreeing about lifetime and failure mode,
 * which is exactly the drift a declared policy exists to prevent.
 */
final class CacheNamespaceRegistry
{
    private const IDENTIFIER = '/^[a-z][a-z0-9_]{0,49}$/';

    /** @var array<string, CacheNamespaceDefinition> */
    private array $definitions = [];

    public function register(CacheNamespaceDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            $identifier = $definition->namespace();

            if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
                throw new LogicException(
                    "Cache namespace [{$identifier}] must be lower case, start with a letter and be at most 50 characters."
                );
            }

            $existing = $this->definitions[$identifier] ?? null;

            if ($existing !== null && $existing !== $definition) {
                throw new LogicException(
                    "Cache namespace [{$identifier}] is already declared by ".$existing::class.'.'
                );
            }

            $this->definitions[$identifier] = $definition;
        }
    }

    /**
     * @return list<CacheNamespaceDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function find(string $identifier): ?CacheNamespaceDefinition
    {
        return $this->definitions[$identifier] ?? null;
    }

    public function isRegistered(CacheNamespaceDefinition $definition): bool
    {
        return ($this->definitions[$definition->namespace()] ?? null) === $definition;
    }

    /**
     * @throws LogicException when the namespace was never registered
     */
    public function assertRegistered(CacheNamespaceDefinition $definition): void
    {
        if (! $this->isRegistered($definition)) {
            throw new LogicException(
                'Cache namespace ['.$definition->namespace().'] is not registered. '.
                'Register it with '.self::class.' from the owning module\'s service provider.'
            );
        }
    }
}
