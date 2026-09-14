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

    /** A size or daily limit refuses this request. */
    case LIMIT_EXCEEDED = 'limit_exceeded';

    /** The media is shorter than the shortest duration an operator allows analysed. */
    case DURATION_TOO_SHORT = 'duration_too_short';

    /** The media is longer than the longest duration the operator or the analyzer allows. */
    case DURATION_TOO_LONG = 'duration_too_long';

    /**
     * The media plays for a length of time, and that length could not be read, so no duration
     * limit can be checked against it and nothing is sent.
     */
    case DURATION_UNAVAILABLE = 'duration_unavailable';

    public function accepted(): bool
    {
        return $this === self::QUEUED || $this === self::REUSED;
    }
}
