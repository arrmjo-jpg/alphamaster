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
    private function __construct(
        public readonly SecretVerification $status,
        public readonly ?string $detail = null,
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
        return [
            'status' => $this->status->value,
            // Beside the machine-readable status, never instead of it (ADR 0031).
            'status_label' => __($this->status->translationKey()),
            'detail' => $this->detail,
        ];
    }
}
