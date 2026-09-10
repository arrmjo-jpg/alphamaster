<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Ai;

/**
 * How long to wait on an AI vendor.
 *
 * Its own setting rather than `operations.provider_timeout_seconds`, because the two
 * describe different things. That one is the ceiling on a message or a challenge —
 * calls that answer in well under a second and whose ten-second default is generous.
 * A generation takes seconds by construction, and a ten-second ceiling would fail
 * every request that was working exactly as intended.
 *
 * Shared by every AI driver so two vendors are given the same patience: a difference
 * here would read as a difference in vendor reliability.
 */
final class AiTimeout
{
    /** Long enough for a paragraph from a slow model, short enough to be a ceiling. */
    private const FALLBACK_SECONDS = 60;

    public static function seconds(): int
    {
        $configured = setting('ai.timeout_seconds', self::FALLBACK_SECONDS);

        return is_int($configured) && $configured > 0 ? $configured : self::FALLBACK_SECONDS;
    }
}
