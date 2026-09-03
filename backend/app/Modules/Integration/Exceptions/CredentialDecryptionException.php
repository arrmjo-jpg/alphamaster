<?php

declare(strict_types=1);

namespace App\Modules\Integration\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a provider's credentials cannot be decrypted (e.g. after an APP_KEY
 * rotation). Returning the ciphertext instead would send it to a vendor as if it
 * were an API key and surface the fault somewhere far less obvious.
 */
class CredentialDecryptionException extends RuntimeException
{
    public function __construct(string $providerId, string $driver, ?Throwable $previous = null)
    {
        parent::__construct(
            "Unable to decrypt credentials for the [{$driver}] provider [{$providerId}]. ".
            'The stored ciphertext is unreadable with the current APP_KEY.',
            0,
            $previous
        );
    }
}
