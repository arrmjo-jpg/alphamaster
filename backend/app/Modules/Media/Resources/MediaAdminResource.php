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
 * The administrative view of a stored file.
 *
 * Deliberately separate from MediaResource rather than a variant of it. This one
 * carries the checksum, the failure reason, the attachment target and the
 * uploader's address — an operator's view of a record. Merging the two would put
 * those on the public endpoint the first time someone reused the class.
 *
 * @property-read MediaFile $resource
 */
class MediaAdminResource extends JsonResource
{
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
            'failure_reason' => $this->resource->failure_reason,
            'attachable_type' => $this->resource->attachable_type,
            'attachable_id' => $this->resource->attachable_id,
            'uploaded_by' => $this->resource->uploader?->email,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
