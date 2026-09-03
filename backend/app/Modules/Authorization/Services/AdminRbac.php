<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Authorization\Exceptions\NotAnAdminAccountException;
use App\Modules\Authorization\Models\Role;
use App\Modules\User\Models\User;
use Illuminate\Support\Collection;

/**
 * The single gateway to admin RBAC.
 *
 * Every read and write passes the account-type boundary first. The User model has to
 * carry Spatie's HasRoles trait for the package to function, which means the raw
 * assignRole() and hasPermissionTo() calls exist on every user, administrator or
 * not. This service is what makes them unreachable in practice: nothing outside it
 * grants, revokes, or evaluates an admin permission, so a regular user cannot acquire
 * administrative capability even if a role row were somehow written for them.
 */
class AdminRbac implements AdminRbacContract
{
    /**
     * Whether this account participates in admin RBAC at all.
     */
    public function participates(User $user): bool
    {
        return $user->account_type->participatesInAdminRbac();
    }

    /**
     * Evaluate an admin permission for a user.
     *
     * A non-admin account is refused outright rather than consulted, so a stray role
     * or permission row can never turn into an allow.
     */
    public function allows(User $user, AdminPermission|string $permission): bool
    {
        if (! $this->participates($user)) {
            return false;
        }

        $name = $permission instanceof AdminPermission ? $permission->value : $permission;

        return $user->hasPermissionTo($name);
    }

    /**
     * Assign a role, refusing any account that is not an administrator.
     *
     * @throws NotAnAdminAccountException
     */
    public function assignRole(User $user, Role|string $role): void
    {
        $this->assertParticipates($user);

        $user->assignRole($role);
    }

    /**
     * Remove a role from an administrator.
     *
     * @throws NotAnAdminAccountException
     */
    public function removeRole(User $user, Role|string $role): void
    {
        $this->assertParticipates($user);

        $user->removeRole($role);
    }

    /**
     * Replace an administrator's roles wholesale.
     *
     * @param  array<int, string>  $roles
     *
     * @throws NotAnAdminAccountException
     */
    public function syncRoles(User $user, array $roles): void
    {
        $this->assertParticipates($user);

        $user->syncRoles($roles);
    }

    /**
     * The role names held by an account. Empty for anyone who is not an administrator,
     * whatever rows may exist.
     *
     * @return array<int, string>
     */
    public function rolesFor(User $user): array
    {
        if (! $this->participates($user)) {
            return [];
        }

        return $user->getRoleNames()->all();
    }

    /**
     * The effective permission names for an account. Empty for a non-administrator.
     *
     * @return array<int, string>
     */
    public function permissionsFor(User $user): array
    {
        if (! $this->participates($user)) {
            return [];
        }

        return $user->getAllPermissions()->pluck('name')->all();
    }

    /**
     * Strip every admin role and permission relation from an account.
     *
     * Used when an administrator is demoted, so no dormant grant survives to take
     * effect if the account were ever promoted again.
     */
    public function revokeAll(User $user): void
    {
        $user->syncRoles([]);
        $user->syncPermissions([]);
    }

    /**
     * All roles, for the admin API.
     *
     * @return Collection<int, Role>
     */
    public function roles(): Collection
    {
        return Role::query()->with('permissions')->orderBy('name')->get();
    }

    /**
     * @throws NotAnAdminAccountException
     */
    private function assertParticipates(User $user): void
    {
        if (! $this->participates($user)) {
            throw new NotAnAdminAccountException($user->id);
        }
    }
}
