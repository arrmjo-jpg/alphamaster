<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * Where one media analysis stands (ADR 0054).
 *
 * Only `completed` and `inconclusive` are assessments. `failed`, `unsupported` and
 * `cancelled` say nothing about the media at all — and none of them, `inconclusive`
 * included, means the media is authentic. A consumer that reads any of them as "human"
 * is making a claim no analyzer supported.
 */
enum MediaAnalysisStatus: string
{
    use HasDisplayLabel;

    case PENDING = 'pending';

    case PROCESSING = 'processing';

    /** The analyzer returned scores for at least one requested type. */
    case COMPLETED = 'completed';

    /** The analyzer ran and could not reach an assessment. */
    case INCONCLUSIVE = 'inconclusive';

    /** The analyzer ran and supports none of the requested types for this media. */
    case UNSUPPORTED = 'unsupported';

    /** The analysis could not be carried out. */
    case FAILED = 'failed';

    /** Withdrawn before it ran, or stopped because the capability or the media went away. */
    case CANCELLED = 'cancelled';

    public function isFinal(): bool
    {
        return ! in_array($this, [self::PENDING, self::PROCESSING], true);
    }

    /**
     * Whether this status carries an assessment of the media.
     */
    public function isAssessment(): bool
    {
        return in_array($this, [self::COMPLETED, self::INCONCLUSIVE], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
