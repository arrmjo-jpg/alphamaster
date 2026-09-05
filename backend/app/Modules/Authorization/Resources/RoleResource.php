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
            'name' => $this->resource->name,
            'permissions' => $this->resource->permissions->pluck('name')->all(),
        ];
    }
}
