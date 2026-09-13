<?php

declare(strict_types=1);

namespace App\Modules\User\Services;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\User\Contracts\AccountTypeManagerContract;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Exceptions\PromotionRefusedException;
use App\Modules\User\Models\SocialIdentity;
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
     *
     * ## Refused while a social identity is linked (ADR 0050 §6)
     *
     * A linked identity is a live way for a vendor to sign in to this account, and
     * promoting it would make that vendor an administrative sign-in method. The refusal
     * raises rather than returning, so it cannot be mistaken for "already an
     * administrator", and it changes nothing: no token is revoked, the type stays, and no
     * identity is unlinked to make room. Once every identity is unlinked the rule no
     * longer applies.
     *
     * The decision is made on the account as it stands under a row lock, not on the model
     * the caller loaded. The link path takes the same lock, so a link racing this
     * promotion is either committed before the check sees it or waits until the
     * promotion is done — and the database refuses the combination either way.
     *
     * The lock lives for this one transaction and guards one state transition. It is not
     * the lock across an editing session that ADR 0038 rejects for configuration.
     *
     * @throws PromotionRefusedException
     */
    public function promote(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            /** @var User $current */
            $current = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($current->account_type === AccountType::ADMIN) {
                return $current;
            }

            $linked = SocialIdentity::query()->linked()->where('user_id', $current->id)->count();

            // Checked before anything changes, so a refusal leaves every token in place.
            if ($linked > 0) {
                throw new PromotionRefusedException($current->id, $linked);
            }

            $current->account_type = AccountType::ADMIN;
            $current->save();

            $current->tokens()->delete();

            return $current->refresh();
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
