<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Services\ProcessorRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads the duration of a video or audio file that was taken in before durations were read
 * (ADR 0054).
 *
 * New uploads get theirs from processing; this is for files already ready. It changes the
 * metadata only, never the file's status, and does nothing for a file that already carries a
 * reading or a recorded reason there is none — probing a file twice tells nobody anything.
 */
class ProbeMediaDuration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $mediaId)
    {
        $this->onQueue('media');
    }

    public function handle(ProcessorRegistry $processors): void
    {
        $media = MediaFile::query()->find($this->mediaId);

        if ($media === null || $media->status !== MediaStatus::READY || ! $media->type->hasDuration()) {
            return;
        }

        if (array_key_exists('duration_available', $media->metadata ?? [])) {
            return;
        }

        $metadata = $processors->for($media->type)?->process($media);

        if ($metadata === null) {
            return;
        }

        $media->forceFill([
            'width' => $metadata['width'] ?? $media->width,
            'height' => $metadata['height'] ?? $media->height,
            'duration_seconds' => $metadata['duration_seconds'] ?? $media->duration_seconds,
            'metadata' => array_merge($media->metadata ?? [], $metadata),
        ])->save();
    }
}
