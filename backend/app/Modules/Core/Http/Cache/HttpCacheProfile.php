<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Cache;

use InvalidArgumentException;

/**
 * How a class of public response may be stored, by browsers and by an edge (ADR 0036,
 * ADR 0053 §2).
 *
 * A route opts in by naming a profile; a route that names none is uncacheable. Profiles are
 * declared rather than written per route, so two endpoints serving the same kind of payload
 * cannot drift into different lifetimes, and a module adds a profile of its own by
 * registering it instead of editing the middleware.
 *
 * The two lifetimes are separate because the two caches are: an edge can be purged when the
 * content changes (ADR 0053 §3) and a browser cannot, so the browser's copy is kept short
 * and the edge's may be long.
 */
final readonly class HttpCacheProfile
{
    /** Anonymous platform configuration: public settings, active languages. */
    public const PUBLIC_CONFIGURATION = 'public-configuration';

    private const NAME = '/^[a-z][a-z0-9-]{0,49}$/';

    public function __construct(
        public string $name,
        public int $browserMaxAge,
        public int $edgeMaxAge,
        public int $staleWhileRevalidate = 0,
        public int $staleIfError = 0,
        /**
         * Whether the payload depends on the negotiated language. A localized response is
         * stored at the edge only when the language is part of its address (ADR 0036:
         * locale is part of the cache identity, not a Vary afterthought).
         */
        public bool $localized = true,
    ) {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException("HTTP cache profile [{$name}] must be a lower-case identifier.");
        }

        foreach ([$browserMaxAge, $edgeMaxAge, $staleWhileRevalidate, $staleIfError] as $seconds) {
            if ($seconds < 0) {
                throw new InvalidArgumentException("HTTP cache profile [{$name}] cannot have a negative lifetime.");
            }
        }
    }

    public function browserDirectives(): string
    {
        return $this->directives($this->browserMaxAge);
    }

    public function edgeDirectives(): string
    {
        return $this->directives($this->edgeMaxAge);
    }

    private function directives(int $maxAge): string
    {
        $parts = ['public', 'max-age='.$maxAge];

        if ($this->staleWhileRevalidate > 0) {
            $parts[] = 'stale-while-revalidate='.$this->staleWhileRevalidate;
        }

        if ($this->staleIfError > 0) {
            $parts[] = 'stale-if-error='.$this->staleIfError;
        }

        return implode(', ', $parts);
    }
}
