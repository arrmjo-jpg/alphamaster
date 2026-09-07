<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use LogicException;

/**
 * Raised when something tries to mint admin:access for an unverified administrator.
 *
 * This is an invariant, not a user-facing outcome, and it should never be caught. The
 * sign-in path checks verification before it reaches issuance, so reaching here means
 * a caller found a route to a token that bypasses that check — which is the bug this
 * exists to make loud rather than a condition to handle.
 *
 * A LogicException rather than a RuntimeException for exactly that reason: the
 * distinction the SPL draws is between a fault in the program and a fault in its
 * circumstances, and this is the first.
 */
class UnverifiedAdministratorException extends LogicException
{
    public static function cannotHoldAdminAccess(string $userId): self
    {
        return new self(
            'Refusing to issue an admin:access token to administrator ['.$userId.'] '.
            'whose email address is not verified (ADR 0012).'
        );
    }
}
