<?php

declare(strict_types=1);

namespace App\Modules\Core\Audit;

use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Models\AuditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Writes the administrative audit trail (ADR 0037).
 *
 * Deliberately small and explicit. A general attribute-diffing auditor records the
 * old and new value of every changed column by default, and `settings.value` holds
 * ciphertext — so the safe design is a recorder that cannot write a value it was
 * not handed, rather than a general one taught what to omit. The omission has to be
 * impossible, not configured.
 *
 * The caller decides what goes into the context, and the caller is where the
 * redaction rule lives, because only the caller knows which of its fields is a
 * secret. `AuditContext` exists so that decision is made explicitly rather than by
 * passing a model's attributes and hoping.
 */
class AuditRecorder implements AuditRecorderContract
{
    /**
     * Record an action that succeeded.
     *
     * @param  array<string, mixed>  $context
     */
    public function succeeded(string $action, ?string $subject = null, array $context = []): AuditRecord
    {
        return $this->write($action, $subject, AuditRecord::OUTCOME_SUCCEEDED, $context);
    }

    /**
     * Record an action that failed, including what the failure was.
     *
     * Failures are recorded for the same reason successes are, and more urgently for
     * an external operation: a CDN purge that failed silently leaves stale content
     * behind an interface reporting success.
     *
     * @param  array<string, mixed>  $context
     */
    public function failed(string $action, ?string $subject = null, array $context = []): AuditRecord
    {
        return $this->write($action, $subject, AuditRecord::OUTCOME_FAILED, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $action, ?string $subject, string $outcome, array $context): AuditRecord
    {
        // The id, not the model: Core may not import a domain module, and an
        // identifier is all the trail needs. Resolved now rather than by later
        // lookup, so an account deleted afterwards still leaves a record of what
        // it did.
        $actorId = Auth::id();

        return AuditRecord::query()->create([
            'actor_id' => is_string($actorId) ? $actorId : null,
            'action' => $action,
            'subject' => $subject,
            'outcome' => $outcome,
            'context' => $context === [] ? null : $context,
            // Joins this row to the log lines from the same request (ADR 0023).
            'correlation_id' => $this->correlationId(),
        ]);
    }

    /**
     * The current request's correlation identifier, where one exists.
     */
    private function correlationId(): ?string
    {
        $value = Context::get('request_id');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
