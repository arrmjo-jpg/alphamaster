<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Data\VerificationAssessment;
use App\Modules\Media\Models\MediaFile;

/**
 * Authenticity assessment for media, when a consuming module asks for one.
 *
 * Nothing implements this yet, deliberately. There is no consumer, and no analyzer is
 * possible here: assessing a video needs frames, and the container has no ffmpeg. The
 * contract is defined so the shape is settled before an implementation arrives.
 *
 * An implementation returns a risk assessment, never a verdict. The platform must not
 * record that a file definitively is or is not machine generated, because no analyzer
 * can support that claim. An implementation must also be re-runnable: a later analyzer,
 * or a later version of the same one, produces a new assessment rather than
 * overwriting what an earlier one concluded.
 */
interface MediaVerifierContract
{
    /**
     * Assess a file, reporting risk rather than a decision.
     */
    public function assess(MediaFile $media): VerificationAssessment;

    /**
     * The analyzer name, recorded on the assessment.
     */
    public function analyzer(): string;

    /**
     * The analyzer version, so an assessment can be attributed and superseded.
     */
    public function version(): string;
}
