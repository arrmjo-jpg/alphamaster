<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaFile;

/**
 * Derives metadata from a stored file.
 *
 * One processor per media type, resolved by type. Only the processors this
 * environment can actually run are registered: thumbnailing needs gd or imagick and
 * video metadata needs ffprobe, neither of which is installed, so those remain
 * contracts without drivers rather than stubs pretending to work.
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
