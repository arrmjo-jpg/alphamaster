<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

/**
 * The analyzer when there is none.
 *
 * Bound by Core only if nothing else is. It answers that nothing is configured and refuses
 * to analyse; it never returns scores, because a detector that answers without looking is
 * not a degraded detector but the absence of one wearing its name.
 */
final class NullMediaAnalyzer implements MediaAnalyzerContract
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function descriptor(): ?AnalyzerDescriptor
    {
        return null;
    }

    public function analyze(MediaAnalysisInput $input): AnalyzerOutcome
    {
        return AnalyzerOutcome::failure('NOT_CONFIGURED', 'No media analyzer is configured.', retryable: false);
    }
}
