<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use RuntimeException;

/**
 * Raised when an upload is refused before anything is written to storage.
 *
 * Carries a machine-readable reason so the API can report why without the message
 * itself becoming the contract.
 */
class MediaValidationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
