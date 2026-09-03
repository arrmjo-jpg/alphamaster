<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Services\ProcessorRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Derives metadata, then marks the file ready.
 */
class ProcessMediaFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $mediaId)
    {
        $this->onQueue('media');
    }

    public function handle(ProcessorRegistry $processors): void
    {
        $media = MediaFile::query()->find($this->mediaId);

        if ($media === null) {
            return;
        }

        // Idempotent: re-running against an already ready file changes nothing.
        if ($media->status === MediaStatus::READY || $media->status->isFailure()) {
            return;
        }

        $media->forceFill(['status' => MediaStatus::PROCESSING])->save();

        $metadata = $processors->for($media->type)?->process($media) ?? [];

        $media->forceFill([
            'width' => $metadata['width'] ?? $media->width,
            'height' => $metadata['height'] ?? $media->height,
            'duration_seconds' => $metadata['duration_seconds'] ?? $media->duration_seconds,
            'metadata' => array_merge($media->metadata ?? [], $metadata),
            'status' => MediaStatus::READY,
        ])->save();
    }

    public function failed(\Throwable $e): void
    {
        MediaFile::query()->find($this->mediaId)?->markFailed(
            MediaStatus::PROCESSING_FAILED,
            'Processing did not complete: '.$e->getMessage()
        );
    }
}
