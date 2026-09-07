<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What an authenticated identity may actually do, by name.
 *
 * A contract in Core because the answer lives in the Authorization module and the
 * callers do not: `/auth/me` is served by Auth, whose dependency rule names Core, User,
 * Integration and the framework. The same direction `LocaleResolverInterface` and
 * `RetentionPolicyContract` already run in — Core declares what it needs to know, and
 * the module that owns the answer binds an implementation.
 *
 * Deliberately separate from `AdminIdentity`. That interface answers whether an identity
 * *is* an administrator, from the account-type discriminator alone, and says in its own
 * docblock that no role lookup belongs behind it. This answers what an administrator
 * *may do*, which is a different question with a different source.
 *
 * Typed against `Authenticatable` rather than the User model, because Core may not
 * import it and `config/auth.php` resolves that model from the environment anyway.
 */
interface EffectiveGrants
{
    /**
     * The role names this identity holds, or an empty list where it holds none.
     *
     * Empty for a regular account even if rows exist: only an administrator
     * participates in the authorization system (ADR 0028), and reporting otherwise
     * would describe a grant that nothing would honour.
     *
     * @return array<int, string>
     */
    public function rolesFor(Authenticatable $user): array;

    /**
     * Every permission name this identity holds, directly or through a role.
     *
     * @return array<int, string>
     */
    public function permissionsFor(Authenticatable $user): array;
}
