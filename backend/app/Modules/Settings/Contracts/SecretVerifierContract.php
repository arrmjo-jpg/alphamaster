<?php

declare(strict_types=1);

namespace App\Modules\Settings\Contracts;

use App\Modules\Settings\Secrets\SecretVerificationResult;

/**
 * Checks a candidate credential with whoever will have to accept it (ADR 0038).
 *
 * The candidate arrives as an argument and leaves with the call. An implementation must
 * not persist it, cache it, log it or write it to configuration that outlives the
 * request — ADR 0038 is explicit that there is no pending state, no second column, and
 * no row holding a credential that is not yet in use. Passing it as a parameter rather
 * than reading it back from storage is what makes that structural instead of a rule
 * somebody has to remember.
 *
 * An implementation never throws for a vendor problem. An unreachable host is an
 * outcome an operator needs described, not a 500 — the same distinction ADR 0017 draws
 * for provider dispatch.
 */
interface SecretVerifierContract
{
    /**
     * The setting reference this verifier speaks for, as `group.key`.
     */
    public function reference(): string;

    /**
     * Try the candidate against the live system, and report what happened.
     */
    public function verify(string $candidate): SecretVerificationResult;
}
