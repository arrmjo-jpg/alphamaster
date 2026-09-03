<?php

declare(strict_types=1);

namespace App\Modules\User\Controllers\Admin;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Exceptions\NotAnAdminAccountException;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\User\Contracts\AccountTypeManagerContract;
use App\Modules\User\Models\User;
use App\Modules\User\Requests\SyncUserRolesRequest;
use Illuminate\Http\JsonResponse;

class UserAdminController extends BaseApiController
{
    public function __construct(
        protected AdminRbacContract $rbac,
        protected AccountTypeManagerContract $accountTypes,
    ) {}

    /**
     * List accounts.
     */
    public function index(): JsonResponse
    {
        // Roles and permissions are eager loaded: presenting a list resolves both for
        // every row, which would otherwise be an N+1 and, with Model::shouldBeStrict()
        // active outside production, a lazy-loading violation rather than merely slow.
        $users = User::query()
            ->with(['roles.permissions', 'permissions'])
            ->orderBy('email')
            ->get()
            ->map(fn (User $user): array => $this->present($user))
            ->all();

        return $this->successResponse($users);
    }

    /**
     * Show a single account.
     */
    public function show(User $user): JsonResponse
    {
        return $this->successResponse($this->present($user));
    }

    /**
     * Promote an account to administrator.
     *
     * The only sanctioned route across the boundary, and it is itself gated on the
     * users.update permission, so an administrator without it cannot create peers.
     */
    public function promote(User $user): JsonResponse
    {
        return $this->successResponse(
            $this->present($this->accountTypes->promote($user)),
            'Account promoted to administrator. Existing sessions were revoked and MFA enrolment is required at next sign-in.'
        );
    }

    /**
     * Demote an administrator to a regular account.
     */
    public function demote(User $user): JsonResponse
    {
        return $this->successResponse(
            $this->present($this->accountTypes->demote($user)),
            'Account demoted. Existing sessions were revoked and admin roles were removed.'
        );
    }

    /**
     * Replace an administrator's roles.
     */
    public function syncRoles(SyncUserRolesRequest $request, User $user): JsonResponse
    {
        /** @var array<int, string> $roles */
        $roles = $request->validated('roles');

        try {
            $this->rbac->syncRoles($user, $roles);
        } catch (NotAnAdminAccountException $e) {
            // Admin roles on a regular account would be a contradiction, so this is
            // refused rather than silently written.
            return $this->errorResponse('NOT_AN_ADMIN_ACCOUNT', $e->getMessage(), null, 422);
        }

        return $this->successResponse($this->present($user->refresh()), 'Roles updated.');
    }

    /**
     * Account representation for the admin API.
     *
     * Roles and permissions read as empty for a regular account even if rows existed,
     * because they are resolved through the boundary rather than off the model.
     *
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        $user->loadMissing(['roles.permissions', 'permissions']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'account_type' => $user->account_type->value,
            'is_active' => $user->is_active,
            'roles' => $this->rbac->rolesFor($user),
            'permissions' => $this->rbac->permissionsFor($user),
        ];
    }
}
