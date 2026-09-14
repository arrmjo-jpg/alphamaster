<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * An advisory reading of an analysis's scores against the thresholds an operator set
 * (ADR 0054).
 *
 * Advisory, and deliberately so. It is derived from the scores and the policy version
 * recorded beside it; a consumer may use it, or apply its own threshold to the scores, or
 * ignore both. It is null when no thresholds are configured, because a classification
 * nobody defined would be the platform inventing a decision.
 */
enum MediaAnalysisClassification: string
{
    use HasDisplayLabel;

    case LIKELY_SYNTHETIC = 'likely_synthetic';

    case LIKELY_AUTHENTIC = 'likely_authentic';

    case INCONCLUSIVE = 'inconclusive';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
