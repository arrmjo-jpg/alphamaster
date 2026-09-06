<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Resources;

use App\Modules\Authorization\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An administrative role and the permissions it carries.
 *
 * @property-read Role $resource
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            // `name` is the immutable machine identifier and does not move.
            // `name_label` sits beside it (ADR 0031) so a client never has to
            // infer a human label from the identifier.
            'name' => $this->resource->name,
            'name_label' => $this->resource->displayLabel(),
            'permissions' => $this->resource->permissions->pluck('name')->all(),
        ];
    }
}
