<?php

declare(strict_types=1);

namespace App\Modules\User\Resources;

use App\Modules\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account as the admin API presents it.
 *
 * Roles and permissions are passed in rather than read off the model. They are
 * resolved through the Authorization boundary, which reports them as empty for a
 * regular account even where rows exist, and that boundary is the application
 * layer's to cross — not presentation's.
 *
 * @property-read User $resource
 */
class UserResource extends JsonResource
{
    /**
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        User $resource,
        private readonly array $roles,
        private readonly array $permissions
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'account_type' => $this->resource->account_type->value,
            'is_active' => $this->resource->is_active,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
        ];
    }
}
