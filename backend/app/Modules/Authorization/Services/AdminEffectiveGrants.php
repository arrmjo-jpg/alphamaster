<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Core\Contracts\EffectiveGrants;
use App\Modules\User\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The Core-facing view of what an account may do (ADR 0028).
 *
 * An adapter, not a second implementation. Every answer comes from AdminRbacContract,
 * which already reports nothing for an account that does not participate — so a caller
 * outside this module cannot accidentally get a more generous answer than the boundary
 * gives, which is the entire reason the boundary exists.
 *
 * An identity that is not this deployment's user model gets an empty list rather than a
 * failure. `config/auth.php` resolves the model from the environment, so a substituted
 * one is configuration rather than a fault, and answering "no grants" is both true and
 * safe — the alternative is a 500 on the endpoint an admin client calls first.
 */
class AdminEffectiveGrants implements EffectiveGrants
{
    public function __construct(private readonly AdminRbacContract $rbac) {}

    /**
     * @return array<int, string>
     */
    public function rolesFor(Authenticatable $user): array
    {
        return $user instanceof User ? $this->rbac->rolesFor($user) : [];
    }

    /**
     * @return array<int, string>
     */
    public function permissionsFor(Authenticatable $user): array
    {
        return $user instanceof User ? $this->rbac->permissionsFor($user) : [];
    }
}
