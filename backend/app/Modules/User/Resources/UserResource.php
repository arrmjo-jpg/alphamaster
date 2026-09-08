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
     * @param  bool  $mfaEnrolled  whether a confirmed second factor exists, asked
     *                             through the Core contract because this module may
     *                             not import the one that owns the answer
     */
    public function __construct(
        User $resource,
        private readonly array $roles,
        private readonly array $permissions,
        private readonly bool $mfaEnrolled = false,
    ) {
        parent::__construct($resource);
    }

    /**
     * The rationale, kept out of the published contract.
     *
     * Scramble reads an inline comment above an array key as that field's public
     * description, so the comments below stay consumer-facing and the reasoning sits
     * here — the same trap this project has been caught by twice before.
     *
     * `phone` is the canonical number and never `phone_hash`: the digest is derived,
     * and publishing a keyed digest of a phone number invites exactly the offline
     * attack it exists to avoid (ADR 0031). An administrator managing an account
     * needs the number they would actually call.
     *
     * Verification is reported twice on purpose, the same pairing
     * AuthenticatedUserResource makes. A client branches on the boolean; an interface
     * showing an account renders the moment. Deriving the first from the second in
     * every client is how one of them ends up treating null as verified.
     *
     * `mfa_enrolled` says whether the account is protected and nothing about how. A
     * method list would tell an attacker holding a stolen admin session which factor
     * to attack, and no secret, destination or recovery code is reachable from behind
     * the contract that answers it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The label sits beside the value it describes and never replaces it
        // (ADR 0030/0031). The value stays the identifier a client matches on;
        // the label is resolved from the request locale each time it is read.
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'account_type' => $this->resource->account_type->value,
            'account_type_label' => $this->resource->account_type->label(),
            'is_active' => $this->resource->is_active,

            // The account's phone number in canonical form, or null.
            'phone' => $this->resource->phone,

            // Whether the address has been confirmed, and when.
            'email_verified' => $this->resource->hasVerifiedEmail(),
            'email_verified_at' => $this->resource->email_verified_at?->toIso8601String(),

            // Whether a confirmed second factor exists. Which one is not published.
            'mfa_enrolled' => $this->mfaEnrolled,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
        ];
    }
}
