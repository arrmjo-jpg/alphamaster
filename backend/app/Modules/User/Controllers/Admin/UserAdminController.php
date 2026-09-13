<?php

declare(strict_types=1);

namespace App\Modules\User\Controllers\Admin;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Exceptions\NotAnAdminAccountException;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Contracts\MfaEnrolmentStatus;
use App\Modules\Core\Contracts\PlatformNotifierContract;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Translation\Phrase;
use App\Modules\User\Contracts\AccountTypeManagerContract;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use App\Modules\User\Requests\StoreUserRequest;
use App\Modules\User\Requests\SyncUserRolesRequest;
use App\Modules\User\Requests\UpdateUserRequest;
use App\Modules\User\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserAdminController extends BaseApiController
{
    public function __construct(
        protected AdminRbacContract $rbac,
        protected AccountTypeManagerContract $accountTypes,
        protected MfaEnrolmentStatus $mfa,
        protected AuditRecorderContract $audit,
        protected PlatformNotifierContract $notifier,
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
     * Create an account.
     *
     * A regular account, always. `users.create` has been in the catalogue and on the
     * seeded administrator role since Phase 6 with nothing serving it — a permission
     * describing an intention the platform ignored — and this is where it is served.
     * It does not become a second route across the administrative boundary:
     * AccountTypeManager remains the only one, so an administrator is still made by
     * creating an account and then promoting it, which needs `users.update` as well.
     *
     * The address is not verified. Nothing here can confirm an address on somebody
     * else's behalf, and marking it verified because an administrator typed it would
     * make verification mean "an administrator typed it".
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $attributes = [
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
            // Cast to `hashed` on the model, so the plaintext never reaches a column.
            'password' => (string) $validated['password'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];

        if (array_key_exists('phone', $validated)) {
            // Filled rather than assigned: `phone` is an accessor that writes two
            // columns, the number and its lookup hash, and mass assignment is the
            // path that goes through it.
            $attributes['phone'] = $validated['phone'] === null ? null : (string) $validated['phone'];
        }

        $user = new User($attributes);

        // The record commits with the account or not at all (ADR 0037): an account
        // that exists without a record of who created it is the state the trail is
        // there to make impossible.
        DB::transaction(function () use ($user, $attributes): void {
            $user->save();

            // The identifier, and whether it may sign in. Not the address, not the
            // number, and self-evidently not the password — the trail is readable by
            // anyone holding `audit.view`.
            $this->audit->succeeded(AuditAction::ACCOUNT_CREATED, $user->id, [
                'active' => $attributes['is_active'],
            ]);
        });

        return $this->successResponse(
            $this->resource($user->refresh()),
            'api.user.created',
            201
        );
    }

    /**
     * Change who an account is.
     *
     * Identity only. What the account may do is changed through promotion, role
     * synchronisation and activation, each behind its own operation and its own
     * permission.
     *
     * Changing the address clears its verification, and that is a security property
     * rather than an inconvenience: leaving `email_verified_at` set would let an
     * administrator move an account onto an address nobody has confirmed while the
     * platform went on treating it as confirmed — which for an administrative account
     * is the difference between a verified identity and an asserted one (ADR 0012).
     * The account verifies the new address through the normal flow.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();
        $changes = [];

        if (array_key_exists('name', $validated)) {
            $changes['name'] = (string) $validated['name'];
        }

        if (array_key_exists('phone', $validated)) {
            // Filled rather than assigned: `phone` is an accessor that writes two
            // columns, the number and its lookup hash, and mass assignment is the
            // path that goes through it.
            $changes['phone'] = $validated['phone'] === null ? null : (string) $validated['phone'];
        }

        if (array_key_exists('preferred_locale', $validated)) {
            $changes['preferred_locale'] = $validated['preferred_locale'] === null
                ? null
                : (string) $validated['preferred_locale'];
        }

        $addressChanged = array_key_exists('email', $validated)
            && (string) $validated['email'] !== $user->email;

        if ($addressChanged) {
            $changes['email'] = (string) $validated['email'];
        }

        $user->fill($changes);

        if ($addressChanged) {
            // Not fillable, and deliberately: nothing outside this method decides that
            // an address stopped being confirmed.
            $user->email_verified_at = null;
        }

        // What actually moved, rather than what was submitted. A request that re-sends
        // a field's current value asks for no change and produces none, and ADR 0037
        // records operations that changed something — the same rule that keeps a
        // redundant activation out of the trail keeps a redundant edit out of it.
        //
        // `phone` is dirty-checked on the canonical column the accessor writes, so a
        // number retyped with different separators is correctly not a change.
        $moved = array_values(array_filter(
            array_keys($changes),
            static fn (string $field): bool => $user->isDirty($field)
        ));

        DB::transaction(function () use ($user, $moved, $addressChanged): void {
            $user->save();

            if ($moved === []) {
                return;
            }

            // Which fields moved, and not one of their values. Recording the new
            // address and number would answer "what changed" by turning the trail
            // into a directory of every operator's contact details, readable by
            // anyone holding `audit.view` — so the field names carry the answer and
            // the account itself carries the values (ADR 0037).
            $this->audit->succeeded(AuditAction::ACCOUNT_UPDATED, $user->id, [
                'changed' => $moved,
                // The security consequence, which is the part of this operation an
                // operator would come looking for.
                'email_verification_cleared' => $addressChanged,
            ]);
        });

        // The account's owner is told that somebody else changed it — after the
        // commit, so a rolled-back edit never announces itself. Nothing is said for a
        // save that moved nothing, for the reason nothing is recorded for one. Which
        // fields moved is not listed: the message would be a second copy of the audit
        // record, and the owner can read their own account.
        if ($moved !== []) {
            $this->notifier->notify($user, 'account.updated', [
                'event' => new Phrase($addressChanged
                    ? 'notifications.event.account_address_changed'
                    : 'notifications.event.account_details_changed'),
            ]);
        }

        return $this->successResponse(
            $this->resource($user->refresh()),
            $addressChanged ? 'api.user.updated_address_unverified' : 'api.user.updated'
        );
    }

    /**
     * Let an account sign in again.
     */
    public function activate(User $user): JsonResponse
    {
        // Recorded only where standing actually moved. Activating an account that was
        // already active changes nothing about who may sign in, and ADR 0037 records
        // operations that change platform behaviour rather than requests that were
        // made — a trail padded with confirmations of the status quo is the noise the
        // decision to leave reads unaudited was avoiding.
        if (! $user->is_active) {
            DB::transaction(function () use ($user): void {
                $user->is_active = true;
                $user->save();

                $this->audit->succeeded(AuditAction::ACCOUNT_ACTIVATED, $user->id);
            });
        }

        return $this->successResponse($this->resource($user->refresh()), 'api.user.activated');
    }

    /**
     * Stop an account signing in.
     *
     * Existing tokens are revoked, matching promotion and demotion: the perimeter
     * refuses a suspended account on the next request either way, but a live token
     * belonging to an account that has been switched off is a loose end, and the two
     * operations that already change standing do not leave one.
     *
     * An administrator cannot deactivate themselves. The platform would accept it and
     * the next request would be refused, leaving somebody locked out of the console
     * they were administering with no way back in from it — so it is refused here,
     * where the intent is still visible, rather than honoured and regretted.
     */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        if ($request->user()?->id === $user->id) {
            return $this->errorResponse(
                'CANNOT_DEACTIVATE_SELF',
                'api.error.user.cannot_deactivate_self',
                null,
                422
            );
        }

        if ($user->is_active) {
            DB::transaction(function () use ($user): void {
                $user->is_active = false;
                $user->save();

                // Counted before the delete, so the record can say what the operation
                // actually reached rather than only what it intended.
                $revoked = $user->tokens()->count();

                $user->tokens()->delete();

                $this->audit->succeeded(AuditAction::ACCOUNT_DEACTIVATED, $user->id, [
                    'tokens_revoked' => $revoked,
                ]);
            });
        }

        return $this->successResponse($this->resource($user->refresh()), 'api.user.deactivated');
    }

    /**
     * Promote an account to administrator.
     *
     * The only sanctioned route across the boundary, and it is itself gated on the
     * users.update permission, so an administrator without it cannot create peers.
     */
    public function promote(User $user): JsonResponse
    {
        // AccountTypeManager runs its own transaction; wrapping the call makes that
        // one a savepoint inside this one, so the boundary crossing and the record of
        // it commit together (ADR 0037). Recording after the manager returned would
        // leave a window where the promotion is durable and the record is not.
        $promoted = DB::transaction(function () use ($user): User {
            $crosses = $user->account_type !== AccountType::ADMIN;
            $revoked = $crosses ? $user->tokens()->count() : 0;

            $result = $this->accountTypes->promote($user);

            // The manager returns the account unchanged when it is already an
            // administrator. Nothing crossed the boundary, so nothing is recorded.
            if ($crosses) {
                $this->audit->succeeded(AuditAction::ACCOUNT_PROMOTED, $result->id, [
                    'tokens_revoked' => $revoked,
                ]);
            }

            return $result;
        });

        return $this->successResponse(
            $this->resource($promoted),
            'Account promoted to administrator. Existing sessions were revoked and MFA enrolment is required at next sign-in.'
        );
    }

    /**
     * Demote an administrator to a regular account.
     */
    public function demote(User $user): JsonResponse
    {
        $demoted = DB::transaction(function () use ($user): User {
            $crosses = $user->account_type === AccountType::ADMIN;
            $revoked = $crosses ? $user->tokens()->count() : 0;
            // Read before the manager strips them: demotion revokes every admin role,
            // and once it has, nothing else in the platform remembers what they were.
            // The trail is the only place that answer can survive.
            $roles = $crosses ? $this->rbac->rolesFor($user) : [];

            $result = $this->accountTypes->demote($user);

            if ($crosses) {
                $this->audit->succeeded(AuditAction::ACCOUNT_DEMOTED, $result->id, [
                    'tokens_revoked' => $revoked,
                    'roles_revoked' => $roles,
                ]);
            }

            return $result;
        });

        return $this->successResponse(
            $this->resource($demoted),
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
            DB::transaction(function () use ($user, $roles): void {
                // Read before the sync, because afterwards nothing remembers which
                // roles were taken away — the same reason demotion reads them first.
                $before = $this->rbac->rolesFor($user);

                $this->rbac->syncRoles($user, $roles);

                $after = $this->rbac->rolesFor($user->refresh());

                $granted = array_values(array_diff($after, $before));
                $revoked = array_values(array_diff($before, $after));

                // Only a change is recorded. Submitting the set an account already
                // holds changes nothing, and a trail that logged it would fill with
                // rows that answer no question.
                if ($granted === [] && $revoked === []) {
                    return;
                }

                $this->audit->succeeded(AuditAction::ACCOUNT_ROLES_CHANGED, $user->id, [
                    'granted' => $granted,
                    'revoked' => $revoked,
                ]);
            });
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
     * Roles, permissions and enrolment state are resolved here rather than inside
     * the Resource: each comes from a boundary this module may not cross, and
     * crossing one is the application layer's job rather than presentation's. The
     * Authorization boundary reports empty for a regular account even where rows
     * exist; the Auth boundary answers a single boolean and can return nothing else.
     */
    private function resource(User $user): UserResource
    {
        $user->loadMissing(['roles.permissions', 'permissions']);

        return new UserResource(
            $user,
            $this->rbac->rolesFor($user),
            $this->rbac->permissionsFor($user),
            $this->mfa->isEnrolled($user),
        );
    }
}
