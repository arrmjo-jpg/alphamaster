<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Whether an identity has a second factor, without saying anything about it.
 *
 * A contract in Core for the same reason `EffectiveGrants` is one: the answer lives in
 * the Auth module and the caller does not. The administrative user list is served by
 * the User module, whose dependency rule forbids importing Auth — so Core declares
 * what it needs to know and the module that owns the answer binds an implementation.
 * The same direction `LocaleResolverInterface` and `RetentionPolicyContract` already
 * run in.
 *
 * It answers one boolean and will not grow. An administrator looking at an account
 * needs to know whether it is protected, which is an operational fact; *how* it is
 * protected is not, and a method list would tell an attacker with a stolen admin
 * session which factor to attack. Nothing behind this interface may return a secret,
 * a recovery code, a destination or a method name.
 *
 * Typed against `Authenticatable` rather than the User model, because Core may not
 * import it and `config/auth.php` resolves that model from the environment anyway.
 */
interface MfaEnrolmentStatus
{
    /**
     * Whether this identity holds at least one confirmed second factor.
     *
     * False for an identity part-way through enrolment: an unconfirmed method proves
     * nothing and must not read as protection.
     */
    public function isEnrolled(Authenticatable $user): bool;
}
