<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Core\Contracts\MediaReferenceContract;
use App\Modules\Core\Media\MediaReference;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaFile;

/**
 * Media files, as content modules refer to them (ADR 0055 §10).
 */
class MediaReferences implements MediaReferenceContract
{
    public function __construct(private readonly MediaServiceContract $media) {}

    public function publicImage(string $mediaId): ?MediaReference
    {
        $file = MediaFile::query()->find($mediaId);

        if ($file === null
            || $file->type !== MediaType::IMAGE
            || ! $file->isServable()
            || ! $file->isPubliclyReadable()) {
            return null;
        }

        $url = $this->media->urlFor($file);

        return $url === null
            ? null
            : new MediaReference($file->id, $url, $file->mime_type, $file->width, $file->height);
    }
}
