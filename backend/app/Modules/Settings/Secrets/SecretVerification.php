<?php

declare(strict_types=1);

namespace App\Modules\Settings\Secrets;

/**
 * What a live verification of a candidate credential concluded (ADR 0038, as extended).
 *
 * Three outcomes, not two. "Unavailable" exists because most secrets have no verifier —
 * `security.api_secret_key` is internal and there is nothing to call — and collapsing
 * that into "verified" would let a rotation that checked nothing report the same thing
 * as one that checked with the vendor. The whole point of verifying is lost the moment
 * a caller cannot tell the two apart.
 */
enum SecretVerification: string
{
    /** A verifier exists, was called, and the vendor accepted the credential. */
    case VERIFIED = 'verified';

    /** A verifier exists, was called, and the credential was not accepted. */
    case FAILED = 'failed';

    /**
     * No verifier is declared for this secret, so nothing was checked. Said out loud
     * rather than implied, because an operator reading "rotated" needs to know whether
     * anything confirmed it.
     */
    case UNAVAILABLE = 'unavailable';

    /**
     * Whether a rotation carrying this outcome may be committed.
     *
     * A failure is the only one that blocks. An absent verifier does not: refusing to
     * rotate a secret nobody can verify would make most credentials unrotatable.
     */
    public function permitsCommit(): bool
    {
        return $this !== self::FAILED;
    }

    public function translationKey(): string
    {
        return 'settings.secret.verification.'.$this->value;
    }
}
