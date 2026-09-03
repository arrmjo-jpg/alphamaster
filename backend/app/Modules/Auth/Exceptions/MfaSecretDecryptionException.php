<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when an MFA secret cannot be decrypted (e.g. after an APP_KEY rotation).
 */
class MfaSecretDecryptionException extends RuntimeException
{
    public function __construct(string $userId, string $type, ?Throwable $previous = null)
    {
        parent::__construct(
            "Unable to decrypt the [{$type}] MFA secret for user [{$userId}]. ".
            'The stored ciphertext is unreadable with the current APP_KEY.',
            0,
            $previous
        );
    }
}
