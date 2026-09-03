<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use RuntimeException;

/**
 * Raised when the login or MFA throttle has been exhausted.
 */
class TooManyAttemptsException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("Too many attempts. Please retry in {$retryAfterSeconds} seconds.");
    }
}
