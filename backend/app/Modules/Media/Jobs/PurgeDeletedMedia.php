<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Contracts\MediaStorageContract;
use App\Modules\Media\Models\MediaFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Removes the bytes behind soft-deleted media, then the row.
 *
 * Deliberately a separate, explicit step. Deleting files as a side effect of a row
 * disappearing makes an accidental delete unrecoverable and a partial failure
 * invisible; doing it on a schedule with a retention window leaves time to notice.
 */
class PurgeDeletedMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  int  $retentionDays  how long a soft-deleted file is kept before purging
     */
    public function __construct(public readonly int $retentionDays = 30)
    {
        $this->onQueue('media');
    }

    public function handle(MediaStorageContract $storage): void
    {
        $cutoff = now()->subDays($this->retentionDays);

        MediaFile::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->chunkById(100, function ($batch) use ($storage): void {
                foreach ($batch as $media) {
                    // An object already gone counts as purged: a retried run must
                    // converge rather than fail on its second pass.
                    try {
                        $storage->delete($media->path, $media->disk);
                    } catch (\Throwable $e) {
                        Log::warning('Media purge could not remove a stored object.', [
                            'media_id' => $media->id,
                            'reason' => $e->getMessage(),
                        ]);

                        // Leave the row so the next run tries again rather than
                        // orphaning bytes nothing points at any more.
                        continue;
                    }

                    $media->forceDelete();
                }
            });
    }
}
