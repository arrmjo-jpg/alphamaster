<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

use App\Modules\Media\Enums\MediaVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Everything needed to take in one file.
 *
 * A value object rather than a long parameter list, so adding an intake concern later
 * does not change every call site.
 */
final readonly class MediaUpload
{
    public function __construct(
        public UploadedFile $file,
        public MediaVisibility $visibility = MediaVisibility::PRIVATE,
        public string $collection = 'default',
        public ?Model $attachable = null,
        public ?string $uploadedBy = null,
        public ?string $disk = null,
    ) {}
}
