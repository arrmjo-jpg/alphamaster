<?php

declare(strict_types=1);

namespace App\Modules\Integration\Resources;

use App\Modules\Integration\Models\CdnPurgeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One purge request, as an operator reads it (ADR 0053 §4).
 *
 * The vendor's error code and message are shown as they were recorded. They are a vendor's
 * sentence about a purge call, never a credential: the driver sends the token in a header
 * and nothing echoes it.
 *
 * @property-read CdnPurgeRequest $resource
 */
class CdnPurgeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'driver' => $this->resource->driver,
            'kind' => $this->resource->kind->value,
            'kind_label' => $this->resource->kind->label(),
            /** @var list<string> */
            'items' => $this->resource->items,
            'item_count' => $this->resource->item_count,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'attempts' => $this->resource->attempts,
            'reason' => $this->resource->reason,
            'requested_by' => $this->resource->requested_by,
            'error_code' => $this->resource->error_code,
            'error_message' => $this->resource->error_message,
            'provider_reference' => $this->resource->provider_reference,
            'available_at' => $this->resource->available_at?->toIso8601String(),
            'completed_at' => $this->resource->completed_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
