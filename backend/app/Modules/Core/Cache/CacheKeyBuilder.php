<?php

declare(strict_types=1);

namespace App\Modules\Core\Cache;

use InvalidArgumentException;

/**
 * Builds every cache key the platform uses (ADR 0035).
 *
 * A key is composed from named parts in one place, never written as a literal:
 *
 *     namespace : resource : discriminators : vN.M
 *
 * `N` is the shape version declared in code, bumped when what is stored changes
 * structure. `M` is the namespace's runtime generation, bumped to invalidate a whole
 * namespace at once without enumerating its keys and without touching anything
 * outside it — which is what makes a scoped flush possible at all.
 *
 * A discriminator earns its place by correctness or is left out. A segment that
 * cannot be justified by a value that would otherwise be wrong is a cache-miss
 * multiplier, a maintenance cost, and a place for the real reasoning to hide.
 */
final readonly class CacheKeyBuilder
{
    /**
     * @param  array<int|string, string|int|bool|null>  $discriminators
     */
    public function build(
        CacheNamespace $namespace,
        string $resource,
        array $discriminators = [],
        int $generation = 0,
    ): string {
        $this->assertResource($resource);

        $parts = [$namespace->value, $resource];

        foreach ($this->normalise($discriminators) as $discriminator) {
            $parts[] = $discriminator;
        }

        $parts[] = 'v'.$namespace->policy()->version.'.'.$generation;

        return implode(':', $parts);
    }

    /**
     * The key holding a namespace's runtime generation.
     *
     * Deliberately outside the namespace's own key space: bumping a generation must
     * not invalidate the counter that records it.
     */
    public function generationKey(CacheNamespace $namespace): string
    {
        return 'cache_generation:'.$namespace->value;
    }

    /**
     * Discriminators in a stable order, rendered unambiguously.
     *
     * Named discriminators are sorted so that the same set always produces the same
     * key regardless of the order a caller listed them — two call sites caching the
     * same thing must not produce two entries.
     *
     * @param  array<int|string, string|int|bool|null>  $discriminators
     * @return array<int, string>
     */
    private function normalise(array $discriminators): array
    {
        $named = [];
        $positional = [];

        foreach ($discriminators as $key => $value) {
            $rendered = $this->render($value);

            if (is_int($key)) {
                $positional[] = $rendered;

                continue;
            }

            $named[$key] = $key.'='.$rendered;
        }

        ksort($named);

        return array_merge($positional, array_values($named));
    }

    /**
     * A discriminator's stable string form.
     *
     * null renders as an explicit marker rather than an empty string, so "no locale"
     * and "the empty locale" cannot collide into one entry.
     */
    private function render(string|int|bool|null $value): string
    {
        return match (true) {
            $value === null => '_null',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    /**
     * A resource is an identifier, not free text.
     *
     * Constrained because an unvalidated resource segment is how an attacker-supplied
     * value mints a cache entry of its own — the failure `SettingService` already
     * guards against by checking a group against the known public list first.
     */
    private function assertResource(string $resource): void
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,99}$/i', $resource) !== 1) {
            throw new InvalidArgumentException(
                "Cache resource [{$resource}] must be an identifier of at most 100 characters."
            );
        }
    }
}
