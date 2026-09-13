<?php

declare(strict_types=1);

namespace App\Modules\Media\Resources;

use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
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
        // Each label sits beside the value it describes and never replaces it
        // (ADR 0030). The value stays the identifier a client matches on; the
        // label is resolved from the request locale every time it is read, so
        // the same record reads differently to two callers and identically to
        // the code.
        return [
            'id' => $this->resource->id,
            'collection' => $this->resource->collection,
            'original_filename' => $this->resource->original_filename,
            'mime_type' => $this->resource->mime_type,
            /*
             * Annotated because reading `->value` off an enum is where the generator
             * loses the set: each of these was published as a bare string, so a client
             * building a filter over them had to keep its own copy of the platform's
             * enum and would be wrong the first time a case was added.
             */
            /** @var MediaType */
            'type' => $this->resource->type->value,
            'type_label' => $this->resource->type->label(),
            'size_bytes' => $this->resource->size_bytes,
            'checksum' => $this->resource->checksum,
            /** @var MediaVisibility */
            'visibility' => $this->resource->visibility->value,
            'visibility_label' => $this->resource->visibility->label(),
            /** @var MediaStatus */
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            /** @var ScanStatus */
            'scan_status' => $this->resource->scan_status->value,
            'scan_status_label' => $this->resource->scan_status->label(),
            'width' => $this->resource->width,
            'height' => $this->resource->height,
            'duration_seconds' => $this->resource->duration_seconds,
            'url' => $this->url,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
