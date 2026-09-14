<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

/**
 * The immediate answer to a request for analysis (ADR 0054).
 *
 * Never the result: analysis is asynchronous. When the request was accepted the ticket
 * carries the analysis id, which the consumer reads later or recognises in the event
 * raised when the analysis finishes.
 */
final readonly class MediaAnalysisTicket
{
    /**
     * @param  list<string>  $unsupportedTypes  requested types the analyzer does not support
     * @param  string|null  $detail  which limit refused the request, or what about the media was unsupported
     * @param  int|null  $limitSeconds  the duration limit that refused it, where one did
     */
    public function __construct(
        public MediaAnalysisOutcome $outcome,
        public ?string $analysisId = null,
        public array $unsupportedTypes = [],
        public ?string $detail = null,
        public ?int $limitSeconds = null,
    ) {}

    public function accepted(): bool
    {
        return $this->outcome->accepted();
    }
}
