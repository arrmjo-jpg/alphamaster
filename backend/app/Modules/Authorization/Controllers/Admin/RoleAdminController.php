<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Controllers\Admin;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Requests\RoleRequest;
use App\Modules\Authorization\Resources\RoleResource;
use App\Modules\Core\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;

class RoleAdminController extends BaseApiController
{
    public function __construct(
        protected AdminRbacContract $rbac
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
        $role = Role::query()->create([
            'name' => $request->validated('name'),
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($request->validated('permissions'));

        return $this->successResponse(new RoleResource($role->refresh()), 'Role created.', 201);
    }

    /**
     * Replace a role's name and permissions.
     */
    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        $role->update(['name' => $request->validated('name')]);
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
        $grouped = Permission::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module')
            ->map(fn ($permissions) => $permissions->pluck('name')->all())
            ->all();

        return $this->successResponse($grouped);
    }
}
