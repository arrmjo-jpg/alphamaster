<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use App\Modules\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The identity behind the presented token.
 *
 * Distinct from the admin account view: it reports the abilities of the token
 * that made the request, and none of the role or permission detail that view
 * carries. The abilities come from the request's own token, so they are passed
 * in rather than read from the user.
 *
 * @property-read User|null $resource
 */
class AuthenticatedUserResource extends JsonResource
{
    /**
     * @param  array<int, string>  $abilities
     */
    public function __construct(?User $resource, private readonly array $abilities)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user?->id,
            'name' => $user?->name,
            'email' => $user?->email,
            'account_type' => $user?->account_type->value,
            'is_active' => (bool) $user?->is_active,
            'abilities' => $this->abilities,
        ];
    }
}
