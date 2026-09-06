<?php

declare(strict_types=1);

namespace App\Modules\Settings\Secrets;

use App\Modules\Settings\Contracts\SecretVerifierContract;
use RuntimeException;

/**
 * Which secrets can be checked before they are committed, and by what (ADR 0038).
 *
 * A registry rather than a method on the definition, for the reason the setting
 * registry itself exists: a declaration says what a setting *is*, and a verifier is a
 * live collaborator with its own dependencies. Putting one inside a value object would
 * make the catalogue impossible to construct without the services it names.
 *
 * Most secrets have no entry, and that is the expected case rather than an omission.
 */
class SecretVerifierRegistry
{
    /**
     * @var array<string, SecretVerifierContract>
     */
    private array $verifiers = [];

    /**
     * A verifier claiming a reference nobody else has claimed.
     *
     * Duplicates raise instead of overwriting. Two verifiers for one credential means
     * one of them is silently never consulted, and the one that wins is whichever
     * provider happened to register last — a difference nothing would surface.
     */
    public function register(SecretVerifierContract $verifier): void
    {
        $reference = $verifier->reference();

        if (isset($this->verifiers[$reference])) {
            throw new RuntimeException("A secret verifier is already registered for [{$reference}].");
        }

        $this->verifiers[$reference] = $verifier;
    }

    public function has(string $reference): bool
    {
        return isset($this->verifiers[$reference]);
    }

    /**
     * Check a candidate, or report that nothing was able to.
     *
     * The absence of a verifier is answered here rather than left to the caller, so
     * every rotation goes through one path and none of them can accidentally treat
     * "nobody checked" as "checked and fine".
     */
    public function verify(string $reference, string $candidate): SecretVerificationResult
    {
        if (! isset($this->verifiers[$reference])) {
            return SecretVerificationResult::unavailable();
        }

        return $this->verifiers[$reference]->verify($candidate);
    }

    /**
     * Every reference that can be verified, for the definitions endpoint to publish.
     *
     * @return array<int, string>
     */
    public function references(): array
    {
        return array_keys($this->verifiers);
    }
}
