<?php

declare(strict_types=1);

namespace App\Modules\Core\Delivery;

use InvalidArgumentException;

/**
 * One request to remove objects from the edge cache, described without naming a vendor.
 *
 * Any module builds one: a content module purging the tags it put on its responses, the
 * media module purging a file that stopped being public, an operator purging a prefix. It
 * is handed to `EdgeCacheContract`, which queues it for whichever CDN driver is
 * configured. Nothing that builds one knows which vendor, zone or API is behind it.
 *
 * Shape is validated here, once, so an invalid item is refused where it was written
 * rather than discovered by a queue worker later. Vendor limits (how many items fit in
 * one call) are the driver's business, not this object's.
 */
final readonly class EdgeInvalidation
{
    /** The longest tag any supported edge accepts in a purge call. */
    public const MAX_TAG_LENGTH = 1024;

    /** Characters a tag may contain: no spaces or commas, which edges use as separators. */
    private const TAG = '/^[A-Za-z0-9._:\/-]+$/';

    private const HOST = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /**
     * @param  list<string>  $items
     */
    private function __construct(
        public EdgeInvalidationKind $kind,
        public array $items,
    ) {}

    /**
     * @param  iterable<string>  $urls  absolute http(s) addresses
     */
    public static function urls(iterable $urls): self
    {
        return self::of(EdgeInvalidationKind::URLS, $urls);
    }

    /**
     * @param  iterable<string>  $tags
     */
    public static function tags(iterable $tags): self
    {
        return self::of(EdgeInvalidationKind::TAGS, $tags);
    }

    /**
     * @param  iterable<string>  $prefixes  absolute http(s) addresses every purged object starts with
     */
    public static function prefixes(iterable $prefixes): self
    {
        return self::of(EdgeInvalidationKind::PREFIXES, $prefixes);
    }

    /**
     * @param  iterable<string>  $hosts
     */
    public static function hosts(iterable $hosts): self
    {
        return self::of(EdgeInvalidationKind::HOSTS, $hosts);
    }

    public static function everything(): self
    {
        return new self(EdgeInvalidationKind::EVERYTHING, []);
    }

    /**
     * Rebuild from stored parts, validating again: a stored row is input too.
     *
     * @param  iterable<mixed>  $items
     */
    public static function of(EdgeInvalidationKind $kind, iterable $items): self
    {
        if ($kind === EdgeInvalidationKind::EVERYTHING) {
            return self::everything();
        }

        $normalised = [];

        foreach ($items as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("An edge invalidation of {$kind->value} takes strings.");
            }

            $item = trim($item);
            $problem = self::problem($kind, $item);

            if ($problem !== null) {
                throw new InvalidArgumentException("[{$item}] cannot be invalidated as {$kind->value}: {$problem}.");
            }

            $normalised[$kind === EdgeInvalidationKind::HOSTS ? strtolower($item) : $item] = true;
        }

        if ($normalised === []) {
            throw new InvalidArgumentException("An edge invalidation of {$kind->value} needs at least one item.");
        }

        return new self($kind, array_keys($normalised));
    }

    /**
     * Why one item is unusable for a kind, or null when it is usable.
     */
    public static function problem(EdgeInvalidationKind $kind, string $item): ?string
    {
        return match ($kind) {
            EdgeInvalidationKind::URLS, EdgeInvalidationKind::PREFIXES => self::addressProblem($item),
            EdgeInvalidationKind::TAGS => match (true) {
                $item === '' => 'empty',
                strlen($item) > self::MAX_TAG_LENGTH => 'too_long',
                preg_match(self::TAG, $item) !== 1 => 'characters',
                default => null,
            },
            EdgeInvalidationKind::HOSTS => preg_match(self::HOST, strtolower($item)) === 1 ? null : 'host',
            EdgeInvalidationKind::EVERYTHING => 'no_items',
        };
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * The same invalidation split into pieces of at most `$size` items.
     *
     * @return list<self>
     */
    public function chunk(int $size): array
    {
        if ($this->kind === EdgeInvalidationKind::EVERYTHING) {
            return [$this];
        }

        return array_map(
            fn (array $items): self => new self($this->kind, $items),
            array_chunk($this->items, max(1, $size)),
        );
    }

    private static function addressProblem(string $url): ?string
    {
        $parts = parse_url($url);

        return match (true) {
            $url === '' => 'empty',
            strlen($url) > 2048 => 'too_long',
            $parts === false, ! isset($parts['scheme'], $parts['host']) => 'not_absolute',
            ! in_array(strtolower($parts['scheme']), ['http', 'https'], true) => 'scheme',
            isset($parts['user']) || isset($parts['pass']) => 'userinfo',
            isset($parts['fragment']) => 'fragment',
            default => null,
        };
    }
}
