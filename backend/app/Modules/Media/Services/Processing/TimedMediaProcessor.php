<?php

declare(strict_types=1);

namespace App\Modules\Media\Services\Processing;

use App\Modules\Media\Contracts\MediaProcessorContract;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaFile;

/**
 * Duration, dimensions and codecs for video and audio, read with ffprobe (ADR 0054).
 *
 * Part of intake, and the reason an analysis request can check a duration limit without
 * reading the file again: the duration is on the record from the moment the file is ready.
 *
 * A file whose duration cannot be read is still ready. Duration is metadata, not a condition
 * of accepting an upload, and a corrupt video is refused by whatever tries to use it rather
 * than by the pipeline that stored it. The reason is recorded as `probe_error`.
 */
class TimedMediaProcessor implements MediaProcessorContract
{
    public function __construct(
        private readonly MediaType $type,
        private readonly FfprobeInspector $inspector,
    ) {}

    public function handles(): MediaType
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function process(MediaFile $media): array
    {
        return array_merge([
            'processor' => 'ffprobe',
            'derived_at' => now()->toIso8601String(),
        ], $this->inspector->inspect($media)->toMetadata());
    }
}
