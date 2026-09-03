<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use RuntimeException;

/**
 * Raised when credentials are valid but the account is suspended.
 */
class AccountInactiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Your account has been suspended or deactivated.');
    }
}
