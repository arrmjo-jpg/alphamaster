<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Declares that an authenticated identity can answer whether it is an
 * administrator.
 *
 * The admin perimeter (ADR 0012, ADR 0028) is enforced in Core, but the account
 * type discriminator that answers the question lives in the User module. Core
 * cannot import that model without inverting the dependency direction, and until
 * now it bridged the gap with `method_exists($user, 'isAdmin')` — which accepts
 * any object that happens to carry a method of that name, whatever it means
 * there, and which static analysis reported as a check that can never fail.
 *
 * The gap is real rather than theoretical: `config/auth.php` resolves the user
 * model from `env('AUTH_MODEL', User::class)`, so the concrete class behind
 * `$request->user()` is configuration, not a guarantee. This interface turns the
 * assumption into a requirement that a substituted model must satisfy
 * deliberately.
 */
interface AdminIdentity
{
    /**
     * Whether this identity is an administrator.
     *
     * Implementations must answer from the account type discriminator alone. A
     * role or permission describes what an administrator may do and never what
     * makes them one, so no role lookup belongs behind this method.
     */
    public function isAdmin(): bool;
}
