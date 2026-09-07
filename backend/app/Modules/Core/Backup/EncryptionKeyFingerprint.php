<?php

declare(strict_types=1);

namespace App\Modules\Core\Backup;

/**
 * A short, one-way identifier for the key an export was written under (ADR 0039).
 *
 * Sufficient to compare and insufficient to attack: a truncated HMAC of a fixed label,
 * keyed by APP_KEY. Not a hash *of* the key — a digest of a secret is a value that can
 * be attacked offline, and truncating it does not change that. Keying a known constant
 * with it gives a stable identifier that reveals nothing about the key itself.
 *
 * It exists so a restore can refuse. Without it, restoring into an environment with a
 * different key writes ciphertext that decrypts to nothing, and the failure surfaces
 * later as an integration that stopped working for reasons nobody can trace. A refusal
 * at restore time is the same information delivered while it is still cheap.
 */
class EncryptionKeyFingerprint
{
    /**
     * The label the fingerprint is taken over. Fixed, and never the key itself.
     */
    private const LABEL = 'alphamaster/configuration-export/v1';

    public function current(): string
    {
        return $this->of((string) config('app.key'));
    }

    /**
     * Whether an export written under some fingerprint can have its encrypted values
     * restored here.
     */
    public function matches(?string $presented): bool
    {
        if (! is_string($presented) || $presented === '') {
            // An export with no fingerprint is treated as one written elsewhere. It may
            // predate this contract or have been edited; either way nothing here can
            // establish that its ciphertext is readable, and guessing is the failure
            // mode this exists to prevent.
            return false;
        }

        return hash_equals($this->current(), $presented);
    }

    /**
     * The fingerprint of a given key, so a test can exercise a mismatch without
     * rewriting the application's own key underneath itself.
     */
    public function of(string $key): string
    {
        return substr(hash_hmac('sha256', self::LABEL, $key), 0, 32);
    }
}
