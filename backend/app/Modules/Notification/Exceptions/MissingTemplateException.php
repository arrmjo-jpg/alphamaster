<?php

declare(strict_types=1);

namespace App\Modules\Notification\Exceptions;

use App\Modules\Notification\Enums\NotificationType;
use RuntimeException;

/**
 * Raised when a notification is dispatched but no active template exists to render it.
 *
 * Sending an empty message would be worse than failing: the recipient learns nothing
 * and nobody learns the template is missing.
 */
class MissingTemplateException extends RuntimeException
{
    public function __construct(public readonly NotificationType $type)
    {
        parent::__construct("No active notification template exists for [{$type->value}].");
    }
}
