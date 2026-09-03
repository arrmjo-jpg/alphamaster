<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Media\Contracts\CdnUrlResolverContract;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Contracts\MediaStorageContract;
use App\Modules\Media\Data\MediaUpload;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Jobs\ScanMediaFile;
use App\Modules\Media\Models\MediaFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one entry point business modules use (ADR 0024).
 *
 * Everything vendor-specific stops here: callers never see Spatie, a disk, or a
 * storage path.
 */
class MediaService implements MediaServiceContract
{
    /**
     * How long a signed URL for private media stays valid.
     */
    private const SIGNED_URL_TTL = 300;

    public function __construct(
        private readonly UploadValidator $validator,
        private readonly MediaStorageContract $storage,
        private readonly CdnUrlResolverContract $cdn,
        private readonly MediaAccessResolver $access,
    ) {}

    /**
     * Validate, store, record, then queue the intake pipeline.
     *
     * Validation happens before anything is written, so a rejected file never reaches
     * storage at all. The record is created inside a transaction with the queued job
     * dispatched after commit, so a worker cannot pick up a row that does not exist.
     */
    public function store(MediaUpload $upload): MediaFile
    {
        $detectedMime = $this->validator->validate($upload->file);

        $disk = $upload->disk ?? $this->storage->defaultDisk();
        $type = MediaType::fromMimeType($detectedMime);
        $directory = $upload->visibility->value.'/'.$type->value.'/'.now()->format('Y/m');

        $checksum = (string) hash_file('sha256', (string) $upload->file->getRealPath());
        $path = $this->storage->put($upload->file, $directory, $disk);

        $media = DB::transaction(function () use ($upload, $disk, $path, $detectedMime, $type, $checksum): MediaFile {
            $media = new MediaFile;

            $media->forceFill([
                'collection' => $upload->collection,
                'disk' => $disk,
                'path' => $path,
                'original_filename' => (string) $upload->file->getClientOriginalName(),
                'mime_type' => $detectedMime,
                'extension' => mb_strtolower((string) $upload->file->getClientOriginalExtension()),
                'type' => $type,
                'size_bytes' => (int) $upload->file->getSize(),
                'checksum' => $checksum,
                'visibility' => $upload->visibility,
                'status' => MediaStatus::UPLOADED,
                // Set explicitly rather than left to the column default: a default only
                // applies to the row, leaving the in-memory model null until a refresh,
                // which the caller has no reason to expect.
                'scan_status' => ScanStatus::NOT_SCANNED,
                'uploaded_by' => $upload->uploadedBy,
            ]);

            if ($upload->attachable instanceof Model) {
                $media->attachable()->associate($upload->attachable);
            }

            $media->save();

            return $media;
        });

        // Dispatched after commit so a worker never races the transaction.
        ScanMediaFile::dispatch($media->id)->afterCommit();

        return $media;
    }

    public function attach(MediaFile $media, Model $attachable, string $collection = 'default'): MediaFile
    {
        $media->attachable()->associate($attachable);
        $media->collection = $collection;
        $media->save();

        return $media;
    }

    /**
     * A URL, or null when this viewer may not have one.
     *
     * Returning null rather than throwing lets a listing render mixed media without
     * the caller catching per-item exceptions; the API turns absence into whatever it
     * needs to.
     */
    public function urlFor(MediaFile $media, ?object $viewer = null): ?string
    {
        if (! $this->access->allows($media, $viewer)) {
            return null;
        }

        if ($media->isPubliclyReadable()) {
            $url = $this->storage->url($media->path, $media->disk);

            return $url === null ? null : $this->cdn->resolve($media, $url);
        }

        // Private media is signed and expiring, and never routed through a CDN: a
        // shared cache in front of a credential-bearing URL is how private files stop
        // being private.
        return $this->storage->temporaryUrl($media->path, $media->disk, self::SIGNED_URL_TTL);
    }

    /**
     * Soft delete only. The bytes are purged by an explicit job so a mistake is
     * recoverable and a failed purge is retryable.
     */
    public function delete(MediaFile $media): void
    {
        $media->delete();
    }
}
