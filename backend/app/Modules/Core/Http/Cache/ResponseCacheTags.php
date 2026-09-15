<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Cache;

/**
 * The edge cache tags the current response carries (ADR 0053 §3).
 *
 * Scoped to one request. A controller or service that knows what a response contains adds
 * the tags for it — built with `EdgeCacheTag`, the same builder the invalidation that
 * purges them uses — and the cache policy middleware writes them in the header the
 * configured edge reads, if the response turns out to be storable there at all.
 */
final class ResponseCacheTags
{
    /** @var array<string, true> */
    private array $tags = [];

    public function add(string ...$tags): void
    {
        foreach ($tags as $tag) {
            if ($tag !== '') {
                $this->tags[$tag] = true;
            }
        }
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->tags);
    }
}
