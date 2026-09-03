<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Data\MediaUpload;
use App\Modules\Media\Models\MediaFile;
use Illuminate\Database\Eloquent\Model;

/**
 * The entry point every business module uses (ADR 0024).
 *
 * The vendor-isolation boundary ADR 0024 requires. A caller deals in MediaFile and
 * nothing else: no storage implementation, no disks, no paths. Whatever backs media
 * underneath can be replaced without a single business model changing.
 */
interface MediaServiceContract
{
    /**
     * Validate, store and register an uploaded file, then queue its intake pipeline.
     */
    public function store(MediaUpload $upload): MediaFile;

    /**
     * Attach an already stored file to an owning record.
     */
    public function attach(MediaFile $media, Model $attachable, string $collection = 'default'): MediaFile;

    /**
     * A URL for this file, or null when the caller may not have one.
     *
     * Private media yields a signed, expiring URL; public media yields a CDN or disk
     * URL. Authorization is decided by the attaching module's policy, never here.
     */
    public function urlFor(MediaFile $media, ?object $viewer = null): ?string;

    /**
     * Soft delete the record. The bytes are purged later by an explicit job, so a
     * mistaken delete stays recoverable and a failed purge stays retryable.
     */
    public function delete(MediaFile $media): void;
}
