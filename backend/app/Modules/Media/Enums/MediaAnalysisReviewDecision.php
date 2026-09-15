<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * What a person concluded after reading an analysis (ADR 0054).
 *
 * A review never edits the analysis it is about. It is recorded beside it, attributed and
 * audited, so the analyzer's reading and the person's stay distinguishable forever.
 */
enum MediaAnalysisReviewDecision: string
{
    use HasDisplayLabel;

    case CONFIRMED_SYNTHETIC = 'confirmed_synthetic';

    case CONFIRMED_AUTHENTIC = 'confirmed_authentic';

    case UNDETERMINED = 'undetermined';
}
