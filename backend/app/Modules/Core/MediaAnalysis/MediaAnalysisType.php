<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * What a media analysis can be asked to look for (ADR 0054).
 *
 * The vocabulary is closed and owned here, so a consumer chooses from it and cannot
 * invent a type an analyzer would not recognise. An analyzer declares the subset it
 * supports; a type it does not support is reported as unsupported, never scored as zero.
 *
 * Every type names an indicator of risk. None of them is a verdict: an analyzer can say a
 * video carries signs of generation, and cannot say that it was or was not generated.
 */
enum MediaAnalysisType: string
{
    use HasDisplayLabel;

    /** Signs that the media was produced by a generative model. */
    case AI_GENERATED = 'ai_generated';

    /** Signs of a synthetic likeness of a real person. */
    case DEEPFAKE = 'deepfake';

    /** Signs that a face was swapped, re-enacted or altered. */
    case FACE_MANIPULATION = 'face_manipulation';

    /** Signs that frames were edited, spliced or composited. */
    case VISUAL_MANIPULATION = 'visual_manipulation';

    /** Signs that speech or sound was synthesised. */
    case SYNTHETIC_AUDIO = 'synthetic_audio';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
