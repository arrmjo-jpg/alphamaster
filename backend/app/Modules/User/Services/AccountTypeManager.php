<?php

declare(strict_types=1);

namespace App\Modules\User\Services;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\User\Contracts\AccountTypeManagerContract;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned way to change an account's type.
 *
 * account_type is excluded from mass assignment, so no controller, request object or
 * profile endpoint can move an account across the boundary. Promotion and demotion
 * happen here, deliberately, with their security consequences attached rather than
 * left to the caller to remember.
 */
class AccountTypeManager implements AccountTypeManagerContract
{
    public function __construct(private readonly AdminRbacContract $rbac) {}

    /**
     * Promote an account to administrator.
     *
     * Existing tokens are revoked as well: a token issued while the account was a
     * regular user carries user:access, and it must not linger alongside the new
     * standing. The account signs in again and, because MFA is mandatory for
     * administrators (ADR 0013), is taken through enrolment before it receives
     * admin:access.
     */
    public function promote(User $user): User
    {
        if ($user->account_type === AccountType::ADMIN) {
            return $user;
        }

        return DB::transaction(function () use ($user): User {
            $user->account_type = AccountType::ADMIN;
            $user->save();

            $user->tokens()->delete();

            return $user->refresh();
        });
    }

    /**
     * Demote an administrator to a regular account.
     *
     * Every token is revoked, so an existing admin:access token cannot outlive the
     * standing that justified it, and every admin role and permission relation is
     * stripped, so nothing dormant would take effect if the account were promoted
     * again later.
     */
    public function demote(User $user): User
    {
        if ($user->account_type === AccountType::USER) {
            return $user;
        }

        return DB::transaction(function () use ($user): User {
            $this->rbac->revokeAll($user);

            $user->account_type = AccountType::USER;
            $user->save();

            $user->tokens()->delete();

            return $user->refresh();
        });
    }
}
