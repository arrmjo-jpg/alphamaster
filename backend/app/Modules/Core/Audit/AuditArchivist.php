<?php

declare(strict_types=1);

namespace App\Modules\Core\Audit;

use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Models\AuditRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The single permitted way a record leaves the active trail (ADR 0037, as extended).
 *
 * ```
 * active trail  →  export  →  integrity verification  →  removal from active store
 * ```
 *
 * Each step gates the next, in that order and no other. Nothing is removed that has not
 * first been exported and then read back and confirmed. An export that cannot be
 * verified removes nothing and says so — which is the whole reason the steps are
 * separate rather than one `delete` with a file written alongside it.
 *
 * **It is triggered, never scheduled.** There is no job here, no queue worker and no
 * synchronisation side effect. A silent periodic cleanup is indistinguishable, from the
 * outside, from evidence disappearing, and the moment it matters is exactly the moment
 * nobody can prove which it was. Requiring a person to ask makes the removal an act
 * with an author.
 */
class AuditArchivist
{
    public function __construct(private readonly AuditRecorderContract $audit) {}

    /**
     * Which records an archival may take, given a retention window in days.
     *
     * `operations.audit_retention_days` describes **eligibility**, not automation: it
     * says what an archival operation is allowed to move, and never causes one to run.
     *
     * @return Builder<AuditRecord>
     */
    public function eligible(int $retentionDays): Builder
    {
        return AuditRecord::query()
            ->where('created_at', '<', Carbon::now()->subDays($retentionDays))
            // A record describing an archival is never itself eligible for archival.
            // Without this, a sufficiently patient sequence of operations erases the
            // evidence that any of them happened, one window at a time, and the trail
            // ends up complete-looking and false.
            ->where('action', '!=', AuditAction::AUDIT_ARCHIVED)
            ->orderBy('id');
    }

    /**
     * Export, verify, then remove — and stop at whichever step fails.
     *
     * The operation is itself audited, with its window, its count, where it wrote and
     * what happened. That record is written whatever the outcome, including when
     * nothing was eligible: "an operator ran this and it moved nothing" is a different
     * fact from "nobody ran it", and only one of them is visible if unsuccessful runs
     * go unrecorded.
     */
    public function archive(int $retentionDays): ArchiveOutcome
    {
        $records = $this->eligible($retentionDays)->get();

        if ($records->isEmpty()) {
            $outcome = ArchiveOutcome::nothingEligible();
            $this->record($outcome, $retentionDays);

            return $outcome;
        }

        // The collection is non-empty by the check above, and `created_at` is set by
        // the database default on insert, so neither access can be null here.
        $oldest = $records->first()->created_at->toIso8601String();
        $newest = $records->last()->created_at->toIso8601String();

        $payload = $this->encode($records->all(), $retentionDays, $oldest, $newest);
        $location = $this->location();

        try {
            $disk = Storage::disk($this->disk());
            $disk->put($location, $payload);

            // Read back from the disk rather than trusting the write. `put` returning
            // true says the driver accepted the bytes, not that they can be retrieved —
            // and "it was written" is precisely the claim that must hold before
            // anything is deleted on the strength of it.
            $readBack = $disk->get($location);

            if (! is_string($readBack) || ! hash_equals(hash('sha256', $payload), hash('sha256', $readBack))) {
                throw new ArchiveVerificationException('The archive did not read back identically.');
            }

            // A second check, and deliberately not a second way of asking the first
            // question. The comparison above proves the file matches what was encoded;
            // this proves what was encoded holds the records about to be deleted. A
            // fault in encode() — a filter, a slice, a key collision — satisfies the
            // hash perfectly, because the hash is taken over whatever encode() produced.
            $decoded = json_decode($readBack, true, 512, JSON_THROW_ON_ERROR);

            $archived = is_array($decoded) ? array_column($decoded['records'] ?? [], 'id') : [];
            $expected = $records->pluck('id')->all();

            if (array_diff($expected, $archived) !== []) {
                throw new ArchiveVerificationException('The archive does not hold every record that would be removed.');
            }
        } catch (Throwable $e) {
            // Nothing is removed. The file, if one was written, is left where it is:
            // deleting the evidence that verification failed is the one reaction
            // guaranteed to make the problem harder to diagnose.
            $outcome = ArchiveOutcome::unverified($records->count(), $location, class_basename($e));
            $this->record($outcome, $retentionDays);

            return $outcome;
        }

        // Verified, and only now. Deleted by primary key rather than by re-running the
        // window: a record written between the read and this line is outside what was
        // archived, and re-deriving the set would remove something no archive holds.
        AuditRecord::query()->whereIn('id', $records->pluck('id'))->getQuery()->delete();

        $outcome = ArchiveOutcome::archived($records->count(), $location, $oldest, $newest);
        $this->record($outcome, $retentionDays);

        return $outcome;
    }

    /**
     * The archive's contents, as a self-describing document.
     *
     * It carries its own window and count so that a file found years later, on a disk
     * nobody remembers configuring, can be read without the platform that wrote it.
     *
     * No secret appears here, because none appears in the trail: the archive is a
     * faithful copy of records that already contain no plaintext, ciphertext, hash or
     * length (ADR 0037). It is worth stating because an archive is a file, and a file
     * travels further than a table.
     *
     * @param  array<int, AuditRecord>  $records
     */
    private function encode(array $records, int $retentionDays, ?string $oldest, ?string $newest): string
    {
        return (string) json_encode([
            'archived_at' => Carbon::now()->toIso8601String(),
            'retention_days' => $retentionDays,
            'window' => ['oldest' => $oldest, 'newest' => $newest],
            'count' => count($records),
            'records' => array_map(static fn (AuditRecord $r): array => [
                'id' => $r->id,
                'actor_id' => $r->actor_id,
                'action' => $r->action,
                'subject' => $r->subject,
                'outcome' => $r->outcome,
                'context' => $r->context,
                'correlation_id' => $r->correlation_id,
                'created_at' => $r->created_at->toIso8601String(),
            ], $records),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Where this archive is written.
     *
     * Named by the moment it was taken, to the second, plus a short random suffix. Two
     * archivals in the same second would otherwise silently overwrite one another —
     * and the file being overwritten is the one holding records that have already been
     * deleted from the active store.
     */
    private function location(): string
    {
        return rtrim((string) config('audit.archive.path'), '/')
            .'/audit-'.Carbon::now()->format('Ymd-His')
            .'-'.bin2hex(random_bytes(4)).'.json';
    }

    private function disk(): string
    {
        return (string) config('audit.archive.disk');
    }

    /**
     * The archival's own record, which does not age out.
     *
     * Who ran it, the window taken, how many records moved, where the archive was
     * written, and the outcome — and never a record's contents, which is what the
     * archive is for.
     */
    private function record(ArchiveOutcome $outcome, int $retentionDays): void
    {
        $context = $outcome->toArray() + ['retention_days' => $retentionDays];

        $outcome->succeeded()
            ? $this->audit->succeeded(AuditAction::AUDIT_ARCHIVED, $this->disk(), $context)
            : $this->audit->failed(AuditAction::AUDIT_ARCHIVED, $this->disk(), $context);
    }
}
