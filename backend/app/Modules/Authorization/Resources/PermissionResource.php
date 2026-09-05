<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Resources;

use App\Modules\Authorization\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in the permission catalogue.
 *
 * A catalogue entry whose technical member is itself a structured key is an
 * object of `name` and `label` (ADR 0031). `name` keeps the identifier every
 * client already matches on; `label` is resolved from the request locale each
 * time it is read.
 *
 * @property-read Permission $resource
 */
class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'label' => $this->resource->displayLabel(),
        ];
    }
}
