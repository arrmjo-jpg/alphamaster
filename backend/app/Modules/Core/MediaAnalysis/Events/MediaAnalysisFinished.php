<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis\Events;

use App\Modules\Core\MediaAnalysis\MediaAnalysisStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An analysis reached a final status (ADR 0054).
 *
 * Carries identifiers and the status, never the scores: a listener reads the result through
 * `MediaAnalysisContract::find()`. It names the consumer that asked, so a module listens for
 * its own analyses and ignores everyone else's. Dispatched only after the result is
 * committed, so a listener never reads a result that is not there.
 *
 * Listening is optional. A consumer may equally read the result later by id.
 */
final class MediaAnalysisFinished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $analysisId,
        public readonly string $mediaId,
        public readonly string $consumer,
        public readonly MediaAnalysisStatus $status,
    ) {}
}
