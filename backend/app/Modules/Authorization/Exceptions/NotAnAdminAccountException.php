<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Exceptions;

use RuntimeException;

/**
 * Raised when an admin role or permission would be granted to an account that is not
 * an administrator.
 *
 * Admin RBAC is administrative authorization infrastructure. A regular user holding a
 * role row would be a contradiction, so the attempt fails loudly rather than quietly
 * creating one.
 */
class NotAnAdminAccountException extends RuntimeException
{
    public function __construct(public readonly string $userId)
    {
        parent::__construct(
            "Account [{$userId}] is not an administrator and cannot hold admin roles or permissions."
        );
    }
}
