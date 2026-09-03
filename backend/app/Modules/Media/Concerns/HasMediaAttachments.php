<?php

declare(strict_types=1);

namespace App\Modules\Media\Concerns;

use App\Modules\Media\Models\MediaFile;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Attaches media to a domain model (ADR 0024).
 *
 * The project-owned trait ADR 0024 requires. A model using it gains media through
 * AlphaMaster types only, so replacing the storage implementation underneath would
 * not touch a single business model.
 */
trait HasMediaAttachments
{
    /**
     * Every file attached to this record.
     */
    public function mediaFiles(): MorphMany
    {
        return $this->morphMany(MediaFile::class, 'attachable');
    }

    /**
     * Files in one named collection, e.g. 'avatar' or 'submissions'.
     */
    public function mediaIn(string $collection): MorphMany
    {
        return $this->mediaFiles()->where('collection', $collection);
    }

    /**
     * The most recent servable file in a collection, which is what a single-file
     * collection such as an avatar actually means.
     */
    public function latestMediaIn(string $collection): ?MediaFile
    {
        return $this->mediaIn($collection)->ready()->latest()->first();
    }
}
