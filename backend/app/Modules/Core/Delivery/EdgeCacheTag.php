<?php

declare(strict_types=1);

namespace App\Modules\Core\Delivery;

use InvalidArgumentException;

/**
 * Builds the tags a response carries to the edge, and a purge names later (ADR 0053 §3).
 *
 * A tag is composed the way a cache key is (ADR 0035): an owner, then parts, never a
 * literal someone typed twice. `EdgeCacheTag::for('settings', 'public')` is what the
 * settings endpoint labels its responses with and what a settings change purges, so the
 * two cannot drift into different spellings.
 *
 * A part that contains anything a tag cannot carry is hashed rather than rejected: an
 * identifier from a database is still a stable identifier after hashing, and a tag that
 * silently lost characters would purge the wrong objects.
 */
final class EdgeCacheTag
{
    private const OWNER = '/^[a-z][a-z0-9_]{0,49}$/';

    private const PART = '/^[A-Za-z0-9._-]{1,100}$/';

    public static function for(string $owner, string|int ...$parts): string
    {
        if (preg_match(self::OWNER, $owner) !== 1) {
            throw new InvalidArgumentException("Edge cache tag owner [{$owner}] must be a lower-case identifier.");
        }

        $segments = [$owner];

        foreach ($parts as $part) {
            $part = (string) $part;
            $segments[] = preg_match(self::PART, $part) === 1 ? $part : 'h'.substr(hash('sha256', $part), 0, 32);
        }

        return implode(':', $segments);
    }
}
