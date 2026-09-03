<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use RuntimeException;

/**
 * Raised when a requested settings group does not exist.
 */
class SettingGroupNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $group)
    {
        parent::__construct("Settings group [{$group}] does not exist.");
    }
}
