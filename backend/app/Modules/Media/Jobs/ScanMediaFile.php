<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Contracts\MediaScannerContract;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Models\MediaFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scans a stored file, then hands it to processing.
 *
 * Takes an id rather than a model so a retry re-reads current state instead of acting
 * on a snapshot taken before an earlier attempt changed something.
 */
class ScanMediaFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $mediaId)
    {
        $this->onQueue('media');
    }

    public function handle(MediaScannerContract $scanner): void
    {
        $media = MediaFile::query()->find($this->mediaId);

        // Deleted between dispatch and execution: nothing to do, and not a failure.
        if ($media === null) {
            return;
        }

        // Idempotent: a retry after the pipeline already moved on must not drag the
        // file back to an earlier state.
        if ($media->status !== MediaStatus::UPLOADED) {
            return;
        }

        $media->forceFill(['status' => MediaStatus::SCANNING])->save();

        $result = $scanner->scan($media);

        if ($result === ScanStatus::INFECTED) {
            $media->forceFill(['scan_status' => $result])->save();
            $media->markFailed(MediaStatus::SCAN_FAILED, 'The file was rejected by the malware scanner.');

            return;
        }

        $media->forceFill(['scan_status' => $result])->save();

        ProcessMediaFile::dispatch($media->id);
    }

    /**
     * A scanner that errors leaves the file unservable rather than optimistically
     * ready: an unscanned file passing as scanned is the failure mode to avoid.
     */
    public function failed(\Throwable $e): void
    {
        MediaFile::query()->find($this->mediaId)?->markFailed(
            MediaStatus::SCAN_FAILED,
            'Scanning did not complete: '.$e->getMessage()
        );
    }
}
