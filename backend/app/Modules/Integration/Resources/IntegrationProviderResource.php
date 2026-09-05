<?php

declare(strict_types=1);

namespace App\Modules\Integration\Resources;

use App\Modules\Integration\Models\IntegrationProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A configured provider.
 *
 * Credentials are reported only as present or absent. The values never leave the
 * server once stored, so a compromised admin session cannot read them back.
 *
 * @property-read IntegrationProvider $resource
 */
class IntegrationProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The label sits beside the value it describes and never replaces it
        // (ADR 0030/0031). The value stays the identifier a client matches on;
        // the label is resolved from the request locale each time it is read.
        return [
            'id' => $this->resource->id,
            'capability' => $this->resource->capability->value,
            'capability_label' => $this->resource->capability->label(),
            'driver' => $this->resource->driver,
            'label' => $this->resource->label,
            'settings' => $this->resource->settings,
            'has_credentials' => $this->resource->hasCredentials(),
            'is_active' => $this->resource->is_active,
            'is_default' => $this->resource->is_default,
            'priority' => $this->resource->priority,
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
