<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use LogicException;

/**
 * Raised when something tries to issue a social sign-in token to an administrator.
 *
 * An invariant, not an outcome, and it should never be caught. The social flow refuses
 * an administrator before issuance, so reaching here means a route to a token exists
 * that bypasses that refusal (ADR 0050 §7). A LogicException for the reason
 * UnverifiedAdministratorException is one.
 */
class SocialAuthenticationForAdministratorException extends LogicException
{
    public static function cannotHoldSocialToken(string $userId): self
    {
        return new self(
            'Refusing to issue a social sign-in token to administrator ['.$userId.']: '.
            'social login is available only to user accounts (ADR 0050).'
        );
    }
}
