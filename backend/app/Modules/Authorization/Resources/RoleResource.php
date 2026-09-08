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
     * Typed precisely rather than as `array<string, mixed>`.
     *
     * Scramble infers a response from the return type it is given, and the loose one
     * published `permissions` as `{}` — an empty object where a list of permission
     * names is returned. A generated client then had nothing to iterate, which is a
     * contract defect rather than a documentation one. Nothing about the response
     * changes here.
     *
     * @return array{id: int, name: string, name_label: string, permissions: list<string>}
     */
    public function toArray(Request $request): array
    {
        // Assigned through a typed local rather than returned inline: Scramble reads
        // the expression, and `Collection::pluck()->all()` widens to `array`, which it
        // published as an empty object. A client generated from that had nothing to
        // iterate — a contract defect rather than a documentation one.
        /** @var list<string> $permissions */
        $permissions = $this->resource->permissions->pluck('name')->all();

        return [
            'id' => $this->resource->id,
            // `name` is the immutable machine identifier and does not move.
            // `name_label` sits beside it (ADR 0031) so a client never has to
            // infer a human label from the identifier.
            'name' => $this->resource->name,
            'name_label' => $this->resource->displayLabel(),
            'permissions' => $permissions,
        ];
    }
}
