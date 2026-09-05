<?php

declare(strict_types=1);

namespace App\Modules\User\Controllers\Admin;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Exceptions\NotAnAdminAccountException;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\User\Contracts\AccountTypeManagerContract;
use App\Modules\User\Models\User;
use App\Modules\User\Requests\SyncUserRolesRequest;
use App\Modules\User\Resources\UserResource;
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
            ->map(fn (User $user): UserResource => $this->resource($user))
            ->all();

        return $this->successResponse($users);
    }

    /**
     * Show a single account.
     */
    public function show(User $user): JsonResponse
    {
        return $this->successResponse($this->resource($user));
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
            $this->resource($this->accountTypes->promote($user)),
            'Account promoted to administrator. Existing sessions were revoked and MFA enrolment is required at next sign-in.'
        );
    }

    /**
     * Demote an administrator to a regular account.
     */
    public function demote(User $user): JsonResponse
    {
        return $this->successResponse(
            $this->resource($this->accountTypes->demote($user)),
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
            return $this->errorResponse('NOT_AN_ADMIN_ACCOUNT', $e->translationKey(), null, 422, $e->translationParameters());
        }

        return $this->successResponse($this->resource($user->refresh()), 'Roles updated.');
    }

    /**
     * Account representation for the admin API.
     *
     * Roles and permissions are resolved here rather than inside the Resource:
     * they come from the Authorization boundary, which reports them as empty for
     * a regular account even if rows existed, and crossing that boundary is the
     * application layer's job rather than presentation's.
     */
    private function resource(User $user): UserResource
    {
        $user->loadMissing(['roles.permissions', 'permissions']);

        return new UserResource(
            $user,
            $this->rbac->rolesFor($user),
            $this->rbac->permissionsFor($user),
        );
    }
}
