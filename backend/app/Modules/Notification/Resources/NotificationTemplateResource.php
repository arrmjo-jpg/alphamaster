<?php

declare(strict_types=1);

namespace App\Modules\Notification\Resources;

use App\Modules\Notification\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A notification template and its per-locale content.
 *
 * @property-read NotificationTemplate $resource
 */
class NotificationTemplateResource extends JsonResource
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
            'type' => $this->resource->type->value,
            'type_label' => $this->resource->type->label(),
            'is_active' => $this->resource->is_active,
            'translations' => $this->resource->translations
                ->map(fn ($translation): array => [
                    'locale' => $translation->locale,
                    'subject' => $translation->subject,
                    'body' => $translation->body,
                ])
                ->values()
                ->all(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
