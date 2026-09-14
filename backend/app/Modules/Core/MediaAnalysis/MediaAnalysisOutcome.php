<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

/**
 * What became of a request for analysis, answered immediately (ADR 0054).
 *
 * Only `queued` and `reused` mean an analysis exists. Every other outcome means nothing
 * was recorded and nothing was sent anywhere, so a consumer can decide what to do without
 * catching an exception.
 */
enum MediaAnalysisOutcome: string
{
    /** A new analysis was recorded and will run. */
    case QUEUED = 'queued';

    /** An equivalent analysis already exists for this media, and is returned instead. */
    case REUSED = 'reused';

    /** An operator has switched the capability off. */
    case DISABLED = 'disabled';

    /** No analyzer is active and fully configured. */
    case NOT_CONFIGURED = 'not_configured';

    case MEDIA_NOT_FOUND = 'media_not_found';

    /** The media is still being taken in, or may not be served. */
    case MEDIA_NOT_READY = 'media_not_ready';

    /** The configured analyzer does not accept this kind of file. */
    case UNSUPPORTED_MEDIA = 'unsupported_media';

    /** The configured analyzer supports none of the requested types. */
    case UNSUPPORTED_TYPES = 'unsupported_types';

    /** A size, duration or daily limit refuses this request. */
    case LIMIT_EXCEEDED = 'limit_exceeded';

    public function accepted(): bool
    {
        return $this === self::QUEUED || $this === self::REUSED;
    }
}
