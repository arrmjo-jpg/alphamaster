<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use App\Modules\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The identity behind the presented token, and what it may do.
 *
 * Three different things travel here and they are worth keeping apart:
 *
 *   * `abilities` are the *token's* scopes — `admin:access`, `mfa:enrol` — which say
 *     what this credential is for, not what its holder is entitled to;
 *   * `roles` and `permissions` are the account's grants, resolved through the
 *     Authorization boundary;
 *   * `account_type` is what makes an account an administrator at all, and is neither
 *     of the above (ADR 0028).
 *
 * A client that has only ever seen `abilities` cannot decide what to render: every
 * administrator's token carries `admin:access` whether they may change a setting or
 * merely look at one. Without the grants the only remaining strategy is to render
 * everything and let the API answer 403, which is a worse interface and an untestable
 * one.
 *
 * All three are passed in rather than read off the model. The abilities belong to the
 * request's token; the grants come from a boundary that reports nothing for an account
 * which does not participate, and crossing that boundary is the application layer's job
 * rather than presentation's — the same rule `UserResource` follows.
 *
 * @property-read User|null $resource
 */
class AuthenticatedUserResource extends JsonResource
{
    /**
     * @param  array<int, string>  $abilities  the presented token's own scopes
     * @param  array<int, string>  $roles  the account's roles, empty for a regular account
     * @param  array<int, string>  $permissions  every permission held, directly or by role
     */
    public function __construct(
        ?User $resource,
        private readonly array $abilities,
        private readonly array $roles = [],
        private readonly array $permissions = [],
    ) {
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

            // Both, and they answer different questions. A client branches on the
            // boolean — show the verification screen or do not — and an interface
            // showing an account renders the moment. Deriving the first from the
            // second in every client is how one of them ends up treating an empty
            // string as verified.
            'email_verified' => (bool) $user?->hasVerifiedEmail(),
            'email_verified_at' => $user?->email_verified_at?->toIso8601String(),

            // The account's own number, in full. It is theirs; the masking on the
            // verification endpoints exists because a *destination* echoed back is a
            // way of reading a number out of the platform, and this is not that.
            'phone' => $user?->phone,
            'phone_verified' => $user?->phone_verified_at !== null,
            'phone_verified_at' => $user?->phone_verified_at?->toIso8601String(),
            'abilities' => $this->abilities,
            // Names, not identifiers. A permission's name is its stable contract and
            // what `can()` is asked with (ADR 0031); its row id is an implementation
            // detail of the vendor table it lives in.
            'roles' => $this->roles,
            'permissions' => $this->permissions,
        ];
    }
}
