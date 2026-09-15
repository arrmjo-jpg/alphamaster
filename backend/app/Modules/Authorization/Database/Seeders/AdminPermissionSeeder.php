<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Database\Seeders;

use App\Modules\Authorization\Contracts\PermissionDefinition;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Services\PermissionCatalogue;
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
     * administrator. "Every" means the whole catalogue, including permissions a module
     * registered (ADR 0052); the other roles are the platform's own baseline, and a
     * module's permissions reach them only when an operator grants them.
     *
     * @return array<string, array<int, PermissionDefinition>>
     */
    private function roleDefinitions(PermissionCatalogue $catalogue): array
    {
        return [
            'super_admin' => $catalogue->all(),
            'administrator' => [
                AdminPermission::USERS_VIEW,
                AdminPermission::USERS_CREATE,
                AdminPermission::USERS_UPDATE,
                AdminPermission::SETTINGS_VIEW,
                AdminPermission::SETTINGS_UPDATE,
                AdminPermission::AUDIT_VIEW,
                // Rollback is seeded here rather than held back the way the security
                // and secret permissions are. Its own permission gates the operation,
                // every setting inside it is still checked against the permission that
                // guards it, secrets cannot be restored at all, the operation is
                // audited and it carries a precondition — so granting it widens what
                // this role can do without moving any security boundary, and
                // administrator is the role that exists to run the system.
                AdminPermission::SETTINGS_ROLLBACK,
                AdminPermission::ROLES_VIEW,
                AdminPermission::PERMISSIONS_VIEW,
                AdminPermission::INTEGRATIONS_VIEW,
                AdminPermission::INTEGRATIONS_UPDATE,
                AdminPermission::NOTIFICATIONS_VIEW,
                AdminPermission::NOTIFICATIONS_UPDATE,
                AdminPermission::MEDIA_VIEW,
                AdminPermission::MEDIA_DELETE,
                // Withholding this from the role that configures the vendor would be
                // theatre: an administrator holding `integrations.update` can activate
                // a provider and choose a model, so they can already cause the spending
                // this permission governs. It is separately grantable for the roles
                // that do not configure anything.
                AdminPermission::AI_USE,
                // Seeing the CDN and purging named objects are day-to-day operation.
                // Purging everything is not: it stays with super_admin until an operator
                // grants it, because its failure mode is an origin outage (ADR 0036).
                AdminPermission::CDN_VIEW,
                AdminPermission::CDN_PURGE,
                // Looking at, asking for and reviewing media analyses is moderation work the
                // role that runs the platform does; configuring the analyzer stays with
                // `integrations.update` and `settings.update`.
                AdminPermission::MEDIA_ANALYSIS_VIEW,
                AdminPermission::MEDIA_ANALYSIS_REQUEST,
                AdminPermission::MEDIA_ANALYSIS_REVIEW,
                // Managing languages is running the platform: the role that could already do it
                // behind the perimeter keeps it. Editors and support never held it on purpose;
                // before this permission existed they could, which is what it closes.
                AdminPermission::LANGUAGES_MANAGE,
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
        $catalogue = app(PermissionCatalogue::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($catalogue->all() as $permission) {
            Permission::query()->firstOrCreate(
                ['name' => $permission->key(), 'guard_name' => 'web'],
                ['module' => $permission->module()],
            );
        }

        foreach ($this->roleDefinitions($catalogue) as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            $role->givePermissionTo(array_map(
                static fn (PermissionDefinition $p): string => $p->key(),
                $permissions
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
