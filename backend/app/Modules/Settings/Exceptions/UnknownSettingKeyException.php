<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use RuntimeException;

/**
 * Raised when an update targets a setting key that is not provisioned.
 *
 * Settings are provisioned by migrations/seeders, never created on the fly by the
 * admin API, so an unknown key is a "not found" condition rather than a bad value.
 */
class UnknownSettingKeyException extends RuntimeException
{
    public function __construct(public readonly string $group, public readonly string $key)
    {
        parent::__construct("Setting [{$group}.{$key}] does not exist. Cannot update an unknown setting.");
    }
}
