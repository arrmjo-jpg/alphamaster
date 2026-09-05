<?php

declare(strict_types=1);

namespace App\Modules\Media\Resources;

use App\Modules\Media\Models\MediaFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public view of a stored file.
 *
 * Deliberately separate from MediaAdminResource: this one carries the media's
 * dimensions and a URL, and none of the operator fields.
 *
 * The URL is passed in rather than resolved here. Producing it needs the media
 * service and the viewer whose access decides whether a signed URL is issued at
 * all, and a Resource that reached for a service would be deciding authorization
 * inside presentation.
 *
 * @property-read MediaFile $resource
 */
class MediaResource extends JsonResource
{
    public function __construct(MediaFile $resource, private readonly ?string $url)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'collection' => $this->resource->collection,
            'original_filename' => $this->resource->original_filename,
            'mime_type' => $this->resource->mime_type,
            'type' => $this->resource->type->value,
            'size_bytes' => $this->resource->size_bytes,
            'checksum' => $this->resource->checksum,
            'visibility' => $this->resource->visibility->value,
            'status' => $this->resource->status->value,
            'scan_status' => $this->resource->scan_status->value,
            'width' => $this->resource->width,
            'height' => $this->resource->height,
            'duration_seconds' => $this->resource->duration_seconds,
            'url' => $this->url,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
