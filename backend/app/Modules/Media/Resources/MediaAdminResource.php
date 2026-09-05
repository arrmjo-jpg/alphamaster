<?php

declare(strict_types=1);

namespace App\Modules\Media\Resources;

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
            'failure_reason' => $this->resource->failure_reason,
            'attachable_type' => $this->resource->attachable_type,
            'attachable_id' => $this->resource->attachable_id,
            'uploaded_by' => $this->resource->uploader?->email,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
