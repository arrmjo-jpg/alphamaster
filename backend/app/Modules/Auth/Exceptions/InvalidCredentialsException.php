<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use RuntimeException;

/**
 * Raised when authentication fails.
 *
 * Deliberately carries no detail about which half was wrong: distinguishing an
 * unknown email from a wrong password would confirm account existence.
 */
class InvalidCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provided credentials are incorrect.');
    }
}
