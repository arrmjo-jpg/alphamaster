<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a secret setting cannot be decrypted (e.g. after an APP_KEY rotation).
 *
 * This must fail loudly: silently returning the stored ciphertext would hand callers
 * an encrypted blob dressed up as a plaintext value.
 */
class SettingDecryptionException extends RuntimeException
{
    public function __construct(string $group, string $key, ?Throwable $previous = null)
    {
        parent::__construct(
            "Unable to decrypt secret setting [{$group}.{$key}]. The stored ciphertext is unreadable with the current APP_KEY.",
            0,
            $previous
        );
    }
}
