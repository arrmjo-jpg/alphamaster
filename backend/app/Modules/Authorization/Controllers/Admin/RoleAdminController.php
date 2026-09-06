<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Controllers\Admin;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Requests\RoleRequest;
use App\Modules\Authorization\Resources\PermissionResource;
use App\Modules\Authorization\Resources\RoleResource;
use App\Modules\Authorization\Services\RoleIdentifier;
use App\Modules\Core\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;

class RoleAdminController extends BaseApiController
{
    public function __construct(
        protected AdminRbacContract $rbac,
        protected RoleIdentifier $identifiers,
    ) {}

    /**
     * List roles and the permissions they carry.
     */
    public function index(): JsonResponse
    {
        return $this->successResponse(
            RoleResource::collection($this->rbac->roles())
        );
    }

    /**
     * Create a role.
     */
    public function store(RoleRequest $request): JsonResponse
    {
        $label = (string) $request->validated('label');

        // The identifier is derived from the label once, here, and never again.
        // Validation has already established that the label yields one.
        $identifier = $this->identifiers->generateUnique($label);

        if ($identifier === null) {
            return $this->errorResponse('ROLE_IDENTIFIER_UNAVAILABLE', 'api.error.authorization.role_identifier_unavailable', null, 422);
        }

        $role = Role::query()->create([
            'name' => $identifier,
            'guard_name' => 'web',
        ]);

        $role->setTranslation(app()->getLocale(), ['label' => $label]);
        $role->syncPermissions($request->validated('permissions'));

        return $this->successResponse(new RoleResource($role->refresh()), 'Role created.', 201);
    }

    /**
     * Replace a role's label and permissions.
     */
    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        // The label changes; the identifier does not. Permissions and assignments
        // reference a role by name, so renaming one would silently detach them.
        $role->setTranslation(app()->getLocale(), ['label' => (string) $request->validated('label')]);
        $role->syncPermissions($request->validated('permissions'));

        return $this->successResponse(new RoleResource($role->refresh()), 'Role updated.');
    }

    /**
     * Delete a role.
     */
    public function destroy(Role $role): JsonResponse
    {
        $role->delete();

        return $this->successResponse(null, 'Role deleted.');
    }

    /**
     * The permission catalogue, grouped by the module that owns each permission.
     */
    public function permissions(): JsonResponse
    {
        // The module grouping is unchanged; each entry gains its label beside the
        // identifier rather than replacing it, so an administrator choosing
        // permissions reads words instead of `users.update` (ADR 0030/0031).
        $grouped = Permission::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module')
            ->map(fn ($permissions) => PermissionResource::collection($permissions)->resolve())
            ->all();

        return $this->successResponse($grouped);
    }
}
