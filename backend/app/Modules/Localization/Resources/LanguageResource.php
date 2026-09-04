<?php

declare(strict_types=1);

namespace App\Modules\Localization\Resources;

use App\Modules\Localization\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Language
 */
class LanguageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'native_name' => $this->native_name,
            // The model casts direction to LanguageDirection and the column is NOT NULL
            // with a default, so the enum is a guarantee here rather than an inference.
            // The is_object() test this replaces could never take its other branch —
            // and that branch, (string) $enum, would have been a TypeError if it ever
            // had.
            'direction' => $this->direction->value,
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'sort_order' => (int) $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
