<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Database\Seeders;

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class AdminPermissionSeeder extends Seeder
{
    /**
     * Roles shipped with the platform, and the permissions each carries.
     *
     * super_admin is granted every permission explicitly. It is a role like any
     * other, deliberately: administrative omnipotence should be something a row
     * says, and a test can assert, rather than an implicit consequence of being an
     * administrator.
     *
     * @return array<string, array<int, AdminPermission>>
     */
    private function roleDefinitions(): array
    {
        return [
            'super_admin' => AdminPermission::cases(),
            'administrator' => [
                AdminPermission::USERS_VIEW,
                AdminPermission::USERS_CREATE,
                AdminPermission::USERS_UPDATE,
                AdminPermission::SETTINGS_VIEW,
                AdminPermission::SETTINGS_UPDATE,
                AdminPermission::ROLES_VIEW,
                AdminPermission::PERMISSIONS_VIEW,
            ],
            'editor' => [
                AdminPermission::USERS_VIEW,
                AdminPermission::SETTINGS_VIEW,
            ],
            'support' => [
                AdminPermission::USERS_VIEW,
            ],
        ];
    }

    /**
     * Provision the admin permission catalogue and the baseline roles.
     *
     * Idempotent and non-destructive, like the settings seeder: it creates what is
     * missing and never strips a permission an operator has attached to a role.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (AdminPermission::cases() as $permission) {
            Permission::query()->firstOrCreate(
                ['name' => $permission->value, 'guard_name' => 'web'],
                ['module' => $permission->module()],
            );
        }

        foreach ($this->roleDefinitions() as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            $role->givePermissionTo(array_map(
                static fn (AdminPermission $p): string => $p->value,
                $permissions
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
