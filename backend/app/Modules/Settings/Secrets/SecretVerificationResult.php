<?php

declare(strict_types=1);

namespace App\Modules\Settings\Secrets;

/**
 * The outcome of checking a candidate credential with whoever will have to accept it.
 *
 * Carries no credential material, and has no field one could be put in. `detail` is a
 * short machine-readable token — an exception class name, a vendor status — chosen
 * because a transport failure's *message* frequently contains the host, the username
 * and occasionally the credential it just tried. The same reasoning the mail tester
 * already applies, kept here so it cannot be undone by a caller reaching for a more
 * helpful string.
 */
final class SecretVerificationResult
{
    /**
     * @param  list<string>  $missing  setting references the verification needed and did
     *                                 not have — references, never values
     */
    private function __construct(
        public readonly SecretVerification $status,
        public readonly ?string $detail = null,
        public readonly array $missing = [],
    ) {}

    public static function verified(): self
    {
        return new self(SecretVerification::VERIFIED);
    }

    public static function failed(?string $detail = null): self
    {
        return new self(SecretVerification::FAILED, $detail);
    }

    /**
     * The verifier could not try the credential, because the configuration it is used
     * with is not complete.
     *
     * Still a failure, because nothing confirmed the candidate and a rotation must not
     * commit on that. But it is a different failure from a rejection, and an operator
     * told "the service did not accept it" goes looking for a password problem when the
     * real one is an unsaved host. So it carries the references that were missing, and
     * the caller can say which settings to complete first.
     *
     * @param  array<int, string>  $missing
     */
    public static function incomplete(array $missing): self
    {
        return new self(SecretVerification::FAILED, 'incomplete', array_values($missing));
    }

    /** Whether the refusal was a missing prerequisite rather than a rejection. */
    public function isIncomplete(): bool
    {
        return $this->missing !== [];
    }

    /**
     * Nothing declares a verifier for this secret, so nothing was checked.
     */
    public static function unavailable(): self
    {
        return new self(SecretVerification::UNAVAILABLE);
    }

    public function permitsCommit(): bool
    {
        return $this->status->permitsCommit();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'status' => $this->status->value,
            // Beside the machine-readable status, never instead of it (ADR 0031).
            'status_label' => __($this->status->translationKey()),
            'detail' => $this->detail,
        ];

        // Only when there is something to name. Setting references, which are public
        // catalogue identifiers — safe in a response for the same reason they are safe
        // in the audit trail.
        if ($this->missing !== []) {
            $result['missing'] = $this->missing;
        }

        return $result;
    }
}
