<?php

declare(strict_types=1);

namespace App\Modules\Core\Controllers\Admin;

use App\Modules\Core\Audit\AuditArchivist;
use App\Modules\Core\Contracts\RetentionPolicyContract;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Core\Resources\AuditRecordResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading the administrative trail, and archiving from it (ADR 0037).
 *
 * The read endpoint exists because `audit.view` has been in the catalogue since Phase
 * 16A with nothing serving it — a permission describing an intention the platform
 * ignored, which is the same fault ADR 0037's extension names about
 * `operations.audit_retention_days`. It is also what makes archival reviewable: an
 * operation that removes records is hard to trust on a platform that never let anyone
 * look at them.
 *
 * There is no write endpoint and there will not be one. The trail is append-only, and
 * the accounts with the most reason to alter it are exactly the ones with
 * administrative access.
 */
class AuditAdminController extends BaseApiController
{
    /**
     * The trail, newest first, filtered.
     *
     * Every filter is an equality or a bound on an indexed column. There is deliberately
     * no free-text search across `context`: it would be a scan over the one column whose
     * contents vary per action, and the questions an operator actually asks — what did
     * this person do, what happened to this setting, what happened that day — are the
     * ones the indexes were built for.
     */
    // `page` is Laravel's, not this controller's, which is why it was absent from the
    // published contract: the paginator reads it off the request and nothing here
    // mentions it. An endpoint whose only pagination control is undocumented can be
    // read one page deep.
    #[QueryParameter('page', 'Which page of results to return.', type: 'int', default: 1)]
    #[QueryParameter('per_page', 'Rows per page.', type: 'int', default: 25)]
    #[QueryParameter('action', 'Exact match on the recorded action.', type: 'string')]
    #[QueryParameter('subject', 'Exact match on the subject the action was taken on.', type: 'string')]
    #[QueryParameter('actor_id', 'Exact match on the account that acted.', type: 'string')]
    #[QueryParameter('outcome', 'Exact match on the recorded outcome.', type: 'string')]
    #[QueryParameter('from', 'Only entries recorded at or after this moment.', type: 'string', format: 'date-time')]
    #[QueryParameter('to', 'Only entries recorded at or before this moment.', type: 'string', format: 'date-time')]
    #[Response(200, type: 'array{success: bool, data: list<AuditRecordResource>, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int, has_more_pages: bool}}}')]
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', '25');
        $perPage = max(1, min($perPage, 100));

        $records = AuditRecord::query()
            ->when($this->filter($request, 'action'), fn ($q, $v) => $q->where('action', $v))
            ->when($this->filter($request, 'subject'), fn ($q, $v) => $q->where('subject', $v))
            ->when($this->filter($request, 'actor_id'), fn ($q, $v) => $q->where('actor_id', $v))
            ->when($this->filter($request, 'outcome'), fn ($q, $v) => $q->where('outcome', $v))
            ->when($this->filter($request, 'from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($this->filter($request, 'to'), fn ($q, $v) => $q->where('created_at', '<=', $v))
            // Then by id: `timestamptz` stores whole seconds here, so two records in the
            // same second would otherwise come back in whatever order the engine felt
            // like — and paging over an unstable sort silently skips and repeats rows.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (AuditRecord $record): array => (new AuditRecordResource($record))->resolve());

        return $this->paginatedResponse($records);
    }

    /**
     * Move records past the retention window out of the active store (ADR 0037).
     *
     * Triggered by a person, never scheduled. `operations.audit_retention_days` says
     * which records this *may* take and never causes it to run — the difference between
     * a removal with an author and a silent periodic cleanup that is indistinguishable,
     * from outside, from evidence disappearing.
     *
     * Export, then verification, then removal. A failure to verify removes nothing and
     * is reported as a failure rather than as an archive of zero records.
     */
    public function archive(AuditArchivist $archivist, RetentionPolicyContract $retention): JsonResponse
    {
        // Asked through a Core contract rather than read from Settings directly: the
        // trail is written by Core on behalf of every module, Settings included, and a
        // Core that depended on Settings would make recording a settings change depend
        // on the thing being changed.
        $outcome = $archivist->archive($retention->auditRetentionDays());

        if (! $outcome->succeeded()) {
            // 500 rather than 422: the request was well formed and the platform could
            // not do what it promised. An operator needs to read this as a fault to
            // investigate, not as something they got wrong.
            return $this->errorResponse(
                'AUDIT_ARCHIVE_UNVERIFIED',
                'api.error.audit.archive_unverified',
                $outcome->toArray(),
                500,
            );
        }

        return $this->successResponse(
            data: $outcome->toArray(),
            message: 'api.audit.archived',
        );
    }

    /**
     * One query filter, or null when it was absent or empty.
     *
     * `?action=` means "everything", not "the records whose action is the empty string"
     * — of which there are none, making the difference between all and nothing.
     *
     * `when()` would reach the same answer today, because it treats an empty string as
     * falsy. This does not rely on that: the behaviour of an endpoint should not rest on
     * the truthiness rules of a helper, which are the sort of thing a framework tightens
     * in a minor release. Stated here, the intent survives that.
     */
    private function filter(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
