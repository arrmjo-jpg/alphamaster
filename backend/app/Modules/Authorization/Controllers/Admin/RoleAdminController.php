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
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Controllers\BaseApiController;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Role definitions.
 *
 * Every write here changes what a group of administrators may do at once, which is why
 * each one is audited (ADR 0037): assigning a role to an account was recorded from the
 * start, and editing the role itself — the same grant, applied to everybody holding it —
 * was not. The trail records identifiers and permission names, never a label's wording.
 */
class RoleAdminController extends BaseApiController
{
    public function __construct(
        protected AdminRbacContract $rbac,
        protected RoleIdentifier $identifiers,
        protected AuditRecorderContract $audit,
    ) {}

    /**
     * List roles and the permissions they carry.
     */
    // Annotated rather than inferred, for the same reason `permissions` below is.
    // Scramble reads RoleResource's returned expression, and a plucked collection
    // widens to `array` — which it published as an empty object, leaving a generated
    // client with nothing to iterate. The shape is written out here instead; the
    // response itself is unchanged.
    #[Response(200, type: 'array{success: bool, data: list<array{id: int, name: string, name_label: string, permissions: list<string>}>}')]
    public function index(): JsonResponse
    {
        return $this->successResponse(
            RoleResource::collection($this->rbac->roles())
        );
    }

    /**
     * Create a role.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{id: int, name: string, name_label: string, permissions: list<string>}}')]
    public function store(RoleRequest $request): JsonResponse
    {
        $label = (string) $request->validated('label');

        // The identifier is derived from the label once, here, and never again.
        // Validation has already established that the label yields one.
        $identifier = $this->identifiers->generateUnique($label);

        if ($identifier === null) {
            return $this->errorResponse('ROLE_IDENTIFIER_UNAVAILABLE', 'api.error.authorization.role_identifier_unavailable', null, 422);
        }

        $role = DB::transaction(function () use ($request, $identifier, $label): Role {
            $role = Role::query()->create([
                'name' => $identifier,
                'guard_name' => 'web',
            ]);

            $role->setTranslation(app()->getLocale(), ['label' => $label]);
            $role->syncPermissions($request->validated('permissions'));

            $this->audit->succeeded(AuditAction::ROLE_CREATED, (string) $role->id, [
                'role' => $role->name,
                'permissions' => $this->permissionNames($role),
            ]);

            return $role;
        });

        return $this->successResponse(new RoleResource($role->refresh()), 'Role created.', 201);
    }

    /**
     * Replace a role's label and permissions.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{id: int, name: string, name_label: string, permissions: list<string>}}')]
    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        // The label changes; the identifier does not. Permissions and assignments
        // reference a role by name, so renaming one would silently detach them.
        $locale = app()->getLocale();
        $label = (string) $request->validated('label');

        // What moved, rather than what was submitted: the editor sends the whole role
        // back on every save, and a trail recording each of those as a change would
        // bury the one save that altered a grant (ADR 0037).
        //
        // The stored row for this locale exactly, not `translate()`: that falls back
        // to another language, so a first Arabic label typed identically to the
        // English one would read as unchanged and go unrecorded.
        $labelMoved = $role->translations()->where('locale', $locale)->value('label') !== $label;
        $before = $this->permissionNames($role);

        DB::transaction(function () use ($request, $role, $locale, $label, $labelMoved, $before): void {
            $role->setTranslation($locale, ['label' => $label]);
            $role->syncPermissions($request->validated('permissions'));

            if ($labelMoved) {
                $this->audit->succeeded(AuditAction::ROLE_UPDATED, (string) $role->id, [
                    'role' => $role->name,
                    'locale' => $locale,
                ]);
            }

            $after = $this->permissionNames($role);
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));

            if ($added !== [] || $removed !== []) {
                $this->audit->succeeded(AuditAction::ROLE_PERMISSIONS_CHANGED, (string) $role->id, [
                    'role' => $role->name,
                    'added' => $added,
                    'removed' => $removed,
                ]);
            }
        });

        return $this->successResponse(new RoleResource($role->refresh()), 'Role updated.');
    }

    /**
     * Delete a role.
     */
    public function destroy(Role $role): JsonResponse
    {
        DB::transaction(function () use ($role): void {
            // Read before the row goes: afterwards nothing remembers what the role
            // granted or to how many people.
            $context = [
                'role' => $role->name,
                'permissions' => $this->permissionNames($role),
                'accounts_affected' => $role->users()->count(),
            ];

            $role->delete();

            $this->audit->succeeded(AuditAction::ROLE_DELETED, (string) $role->id, $context);
        });

        return $this->successResponse(null, 'Role deleted.');
    }

    /**
     * The permissions a role grants, by catalogue name, in a stable order — read from
     * the database rather than the loaded relation, which a sync may have left stale.
     *
     * @return list<string>
     */
    private function permissionNames(Role $role): array
    {
        $names = array_map(
            static fn (mixed $name): string => is_string($name) ? $name : '',
            $role->permissions()->pluck('name')->all()
        );

        sort($names);

        return $names;
    }

    /**
     * The permission catalogue, grouped by the module that owns each permission.
     */
    #[Response(200, type: 'array{success: bool, data: array<string, list<array{key: string, label: string}>>}')]
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
