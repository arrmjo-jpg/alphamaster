<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * How long the platform keeps the things it keeps (ADR 0037).
 *
 * A contract rather than a direct read, because the value lives in Settings and Core
 * may not depend on a domain module. The same shape LocaleResolverInterface already
 * uses: Core declares what it needs to know, and the module that owns the answer binds
 * an implementation.
 *
 * The direction matters beyond tidiness. The audit trail is written by Core on behalf
 * of every module, including Settings; if Core read Settings directly, recording a
 * settings change would depend on the thing being changed.
 */
interface RetentionPolicyContract
{
    /**
     * How many days of administrative trail the active store keeps.
     *
     * Describes **eligibility**, never automation: it says which records an archival
     * operation may take, and nothing here ever causes one to run.
     */
    public function auditRetentionDays(): int;
}
