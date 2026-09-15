<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Media\MediaReferenceRegistry;
use App\Modules\Media\Models\MediaFile;
use Illuminate\Support\Facades\DB;

/**
 * Purges every public response that shows a file which stopped being servable (ADR 0058 §7).
 *
 * Media asks Core's registry which responses those are and never names the modules that answer.
 * The purge waits for the commit, so a rolled-back deletion purges nothing; it is rescued, so a
 * CDN that cannot be reached does not turn a deletion into a failure — the purge request records
 * its own outcome.
 */
final class MediaReferenceInvalidation
{
    public function __construct(private readonly MediaReferenceRegistry $references) {}

    /**
     * Whether a saved change made the file unservable to anonymous readers.
     */
    public static function becameUnservable(MediaFile $media): bool
    {
        return ($media->wasChanged('status') && $media->status->isFailure())
            || ($media->wasChanged('scan_status') && ! $media->scan_status->permitsServing())
            || $media->wasChanged('visibility');
    }

    public function forMedia(string $mediaId, string $reason): void
    {
        $tags = $this->references->edgeTagsFor($mediaId);

        if ($tags === []) {
            return;
        }

        DB::afterCommit(static fn () => rescue(
            static fn () => app(EdgeCacheContract::class)->invalidate(EdgeInvalidation::tags($tags), $reason),
        ));
    }
}
