<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Contracts;

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Authorization\Exceptions\NotAnAdminAccountException;
use App\Modules\Authorization\Models\Role;
use App\Modules\User\Models\User;
use Illuminate\Support\Collection;

interface AdminRbacContract
{
    /**
     * Whether this account participates in admin RBAC at all.
     */
    public function participates(User $user): bool;

    /**
     * Evaluate an admin permission. Always false for a non-admin account.
     */
    public function allows(User $user, AdminPermission|string $permission): bool;

    /**
     * @throws NotAnAdminAccountException
     */
    public function assignRole(User $user, Role|string $role): void;

    /**
     * @throws NotAnAdminAccountException
     */
    public function removeRole(User $user, Role|string $role): void;

    /**
     * @param  array<int, string>  $roles
     *
     * @throws NotAnAdminAccountException
     */
    public function syncRoles(User $user, array $roles): void;

    /**
     * @return array<int, string>
     */
    public function rolesFor(User $user): array;

    /**
     * @return array<int, string>
     */
    public function permissionsFor(User $user): array;

    /**
     * Strip every admin role and permission relation from an account.
     */
    public function revokeAll(User $user): void;

    /**
     * @return Collection<int, Role>
     */
    public function roles(): Collection;
}
