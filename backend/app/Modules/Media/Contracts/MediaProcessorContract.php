<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaFile;

/**
 * Derives metadata from a stored file.
 *
 * One processor per media type, resolved by type. Only what this environment can
 * actually derive is reported: thumbnailing needs gd or imagick, which is not installed,
 * so it remains a contract without a driver rather than a stub pretending to work. Video
 * and audio metadata is read with ffprobe, which the image installs.
 */
interface MediaProcessorContract
{
    /**
     * The media type this processor handles.
     */
    public function handles(): MediaType;

    /**
     * Extract what it can, returning metadata to merge onto the record.
     *
     * @return array<string, mixed>
     */
    public function process(MediaFile $media): array;
}
