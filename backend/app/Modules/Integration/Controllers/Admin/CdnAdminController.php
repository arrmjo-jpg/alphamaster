<?php

declare(strict_types=1);

namespace App\Modules\Integration\Controllers\Admin;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Contracts\EffectiveGrants;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Delivery\EdgeInvalidationKind;
use App\Modules\Integration\Enums\CdnPurgeStatus;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Jobs\ProcessCdnPurgeRequest;
use App\Modules\Integration\Models\CdnPurgeRequest;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Integration\Requests\StoreCdnPurgeRequest;
use App\Modules\Integration\Resources\CdnPurgeRequestResource;
use App\Modules\Integration\Services\CdnEdgeCache;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * The CDN workspace (ADR 0036, ADR 0053).
 *
 * Vendor-neutral by construction: every vendor fact — which fields configure it, what its
 * limits are, what it calls a scope — is asked of the driver, so this controller serves
 * Cloudflare today and the next vendor without an edit.
 *
 * What it will not do is report a purge as done. A purge is queued and answered 202; its
 * outcome is on the request row, which this workspace lists with its status, attempts and
 * the vendor's own error. Purging everything takes its own permission, the verified scope's
 * name typed back, and is rate-limited per operator, because ADR 0036 treats it as an
 * incident tool rather than an invalidation.
 */
class CdnAdminController extends BaseApiController
{
    private const PURGE_EVERYTHING_PER_HOUR = 3;

    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly CdnEdgeCache $edge,
        private readonly AuditRecorderContract $audit,
        private readonly EffectiveGrants $grants,
    ) {}

    /**
     * The CDN's state: the configured provider, what verification found, the limits that follow, delivery settings and the purge queue.
     */
    #[Response(200, type: 'array{success: bool, data: array{configured: bool, provider: array{id: string, driver: string, label: string, is_active: bool, has_credentials: bool, settings: array<string, string|null>}|null, fields: array{settings: list<string>, credentials: list<string>}, missing: list<string>, verification: array{scope_name: string|null, scope_status: string|null, plan: string|null, verified_at: string|null, error_code: string|null, error_message: string|null}|null, limits: list<array{kind: string, supported: bool, items_per_request: int|null, requests_per_minute: int|null}>, tag_header: string|null, delivery: array{enabled: bool, base_url: string|null}, queue: array{pending: int, processing: int, failed: int, succeeded_last_day: int}, last_attempt: array{status: string, at: string, error_code: string|null, error_message: string|null}|null}}')]
    public function show(): JsonResponse
    {
        $provider = $this->edge->configuredProvider();
        $driver = $provider !== null && $this->edge->hasDriver($provider) ? $this->edge->driverFor($provider) : null;
        $fields = $driver?->configurationFields() ?? ['settings' => [], 'credentials' => []];

        return $this->successResponse([
            'configured' => $this->edge->usableProvider() !== null,
            'provider' => $provider === null ? null : [
                'id' => $provider->id,
                'driver' => $provider->driver,
                'label' => $provider->label,
                'is_active' => $provider->is_active,
                'has_credentials' => $provider->hasCredentials(),
                // Only the fields the driver declares; what verification detected is
                // reported below, under its own name.
                'settings' => collect($fields['settings'])
                    ->mapWithKeys(fn (string $field): array => [$field => $this->stringOrNull($provider->settings[$field] ?? null)])
                    ->all(),
            ],
            'fields' => $fields,
            'missing' => $provider === null ? [] : $this->edge->missingConfiguration($provider),
            'verification' => $provider === null ? null : $this->verification($provider),
            'limits' => $provider !== null && $driver !== null ? $driver->limits($provider)->toArray() : [],
            'tag_header' => $this->edge->tagHeader(),
            'delivery' => [
                'enabled' => (bool) setting('cdn.enabled', false),
                'base_url' => $this->stringOrNull(setting('cdn.base_url')),
            ],
            'queue' => [
                'pending' => CdnPurgeRequest::query()->where('status', CdnPurgeStatus::PENDING->value)->count(),
                'processing' => CdnPurgeRequest::query()->where('status', CdnPurgeStatus::PROCESSING->value)->count(),
                'failed' => CdnPurgeRequest::query()->where('status', CdnPurgeStatus::FAILED->value)->count(),
                'succeeded_last_day' => CdnPurgeRequest::query()
                    ->where('status', CdnPurgeStatus::SUCCEEDED->value)
                    ->where('completed_at', '>=', now()->subDay())
                    ->count(),
            ],
            'last_attempt' => $this->lastAttempt(),
        ]);
    }

    /**
     * Ask the vendor about the configured scope with the stored credential, and keep what it reports.
     *
     * The plan detected here sets the limits purges are split and paced by. Recorded in the audit trail either way.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{reachable: bool, verification: array{scope_name: string|null, scope_status: string|null, plan: string|null, verified_at: string|null, error_code: string|null, error_message: string|null}, limits: list<array{kind: string, supported: bool, items_per_request: int|null, requests_per_minute: int|null}>}}')]
    #[Response(404, description: 'CDN_NOT_CONFIGURED: there is no CDN provider row.')]
    #[Response(422, description: 'PROVIDER_CONFIGURATION_INCOMPLETE: the provider lacks required configuration; details name the fields.')]
    public function verify(): JsonResponse
    {
        $provider = $this->edge->configuredProvider();

        if ($provider === null || ! $this->edge->hasDriver($provider)) {
            return $this->errorResponse('CDN_NOT_CONFIGURED', 'api.error.cdn.not_configured', null, 404);
        }

        $missing = $this->edge->missingConfiguration($provider);

        if ($missing !== []) {
            return $this->errorResponse(
                'PROVIDER_CONFIGURATION_INCOMPLETE',
                'api.error.integration.provider_configuration_incomplete',
                ['missing' => $missing],
                422
            );
        }

        $report = $this->edge->driverFor($provider)->verify($provider);

        $settings = $provider->settings ?? [];
        $settings['verified_at'] = now()->toIso8601String();
        $settings['detected_error_code'] = $report->errorCode;
        $settings['detected_error_message'] = $report->errorMessage;

        if ($report->reachable) {
            // A failed check keeps the last good detection: a vendor outage must not
            // reset a verified zone's limits to the most restrictive plan.
            $settings['detected_scope_name'] = $report->name;
            $settings['detected_scope_status'] = $report->status;
            $settings['detected_plan'] = $report->plan;
        }

        $provider->forceFill(['settings' => $settings])->save();

        $context = [
            'provider' => $provider->driver,
            'reachable' => $report->reachable,
            'scope_name' => $report->name,
            'plan' => $report->plan,
            'error_code' => $report->errorCode,
        ];

        $report->reachable
            ? $this->audit->succeeded(AuditAction::CDN_SCOPE_VERIFIED, $provider->driver, $context)
            : $this->audit->failed(AuditAction::CDN_SCOPE_VERIFIED, $provider->driver, $context);

        $provider->refresh();

        return $this->successResponse([
            'reachable' => $report->reachable,
            'verification' => $this->verification($provider),
            'limits' => $this->edge->driverFor($provider)->limits($provider)->toArray(),
        ], $report->reachable ? 'api.cdn.verified' : 'api.cdn.verification_failed');
    }

    /**
     * Purge requests, newest first, with their outcome.
     */
    #[QueryParameter('status', description: 'Only requests in this state.', type: 'string', required: false)]
    #[QueryParameter('page', description: 'Page number.', type: 'int', required: false)]
    #[Response(200, type: 'array{success: bool, data: list<array{id: string, driver: string, kind: string, kind_label: string, items: list<string>, item_count: int, status: string, status_label: string, attempts: int, reason: string|null, requested_by: string|null, error_code: string|null, error_message: string|null, provider_reference: string|null, available_at: string|null, completed_at: string|null, created_at: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}')]
    public function purges(Request $request): JsonResponse
    {
        $status = CdnPurgeStatus::tryFrom((string) $request->query('status', ''));

        $page = CdnPurgeRequest::query()
            ->when($status !== null, static fn ($query) => $query->where('status', $status?->value))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE);

        return $this->successResponse(
            CdnPurgeRequestResource::collection($page->getCollection())->resolve($request),
            meta: [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    /**
     * Ask the edge to purge named objects, or everything.
     *
     * Queued, never performed inline: the answer is the recorded requests, and each one's outcome is read from the purge list. Recorded in the audit trail.
     */
    #[Response(202, type: 'array{success: bool, message: string, data: list<array{id: string, driver: string, kind: string, kind_label: string, items: list<string>, item_count: int, status: string, status_label: string, attempts: int, reason: string|null, requested_by: string|null, error_code: string|null, error_message: string|null, provider_reference: string|null, available_at: string|null, completed_at: string|null, created_at: string|null}>}')]
    #[Response(403, description: 'FORBIDDEN: purging everything needs cdn.purge_everything.')]
    #[Response(409, description: 'CDN_NOT_CONFIGURED: no active, configured CDN provider. CDN_NOT_VERIFIED: purging everything needs a verified scope.')]
    #[Response(422, description: 'CDN_CONFIRMATION_MISMATCH: the confirmation is not the verified scope name. CDN_KIND_UNSUPPORTED: the vendor cannot purge this kind.')]
    #[Response(429, description: 'TOO_MANY_ATTEMPTS: purging everything is limited per operator.')]
    public function purge(StoreCdnPurgeRequest $request): JsonResponse
    {
        $kind = EdgeInvalidationKind::from((string) $request->validated('kind'));
        $provider = $this->edge->usableProvider();

        if ($provider === null) {
            return $this->errorResponse('CDN_NOT_CONFIGURED', 'api.error.cdn.not_configured', null, 409);
        }

        if ($kind === EdgeInvalidationKind::EVERYTHING) {
            $refusal = $this->refuseEverything($request, $provider);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        try {
            $invalidation = EdgeInvalidation::of($kind, (array) $request->validated('items', []));
        } catch (InvalidArgumentException) {
            return $this->errorResponse('VALIDATION_ERROR', 'api.error.validation_failed', ['items' => [__('validation.custom.cdn_item.invalid')]], 422);
        }

        $reason = $request->validated('reason');
        $receipt = $this->edge->invalidate($invalidation, is_string($reason) ? $reason : null);

        if (! $receipt->queued()) {
            return $receipt->status === $receipt::UNSUPPORTED
                ? $this->errorResponse('CDN_KIND_UNSUPPORTED', 'api.error.cdn.kind_unsupported', ['kind' => $kind->value], 422)
                : $this->errorResponse('CDN_NOT_CONFIGURED', 'api.error.cdn.not_configured', null, 409);
        }

        $this->audit->succeeded(
            $kind === EdgeInvalidationKind::EVERYTHING ? AuditAction::CDN_PURGE_EVERYTHING_REQUESTED : AuditAction::CDN_PURGE_REQUESTED,
            $provider->driver,
            [
                'kind' => $kind->value,
                'item_count' => $invalidation->count(),
                'requests' => $receipt->requestIds,
                'reason' => $reason,
            ],
        );

        $rows = CdnPurgeRequest::query()->whereIn('id', $receipt->requestIds)->orderBy('created_at')->get();

        return $this->successResponse(
            CdnPurgeRequestResource::collection($rows)->resolve($request),
            'api.cdn.purge_queued',
            202,
        );
    }

    /**
     * Put a failed purge back in the queue. Recorded in the audit trail.
     */
    #[Response(202, type: 'array{success: bool, message: string, data: array{id: string, driver: string, kind: string, kind_label: string, items: list<string>, item_count: int, status: string, status_label: string, attempts: int, reason: string|null, requested_by: string|null, error_code: string|null, error_message: string|null, provider_reference: string|null, available_at: string|null, completed_at: string|null, created_at: string|null}}')]
    #[Response(404, description: 'NOT_FOUND: no such purge request.')]
    #[Response(409, description: 'CDN_PURGE_NOT_RETRYABLE: only a failed purge can be retried. CDN_NOT_CONFIGURED: no usable provider.')]
    public function retry(Request $request, string $purge): JsonResponse
    {
        $row = CdnPurgeRequest::query()->find($purge);

        if ($row === null) {
            return $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404);
        }

        if ($row->kind === EdgeInvalidationKind::EVERYTHING && ! $this->mayPurgeEverything($request)) {
            return $this->errorResponse('FORBIDDEN', 'api.error.cdn.purge_everything_forbidden', null, 403);
        }

        if ($row->status !== CdnPurgeStatus::FAILED) {
            return $this->errorResponse('CDN_PURGE_NOT_RETRYABLE', 'api.error.cdn.purge_not_retryable', null, 409);
        }

        $provider = $this->edge->usableProvider();

        if ($provider === null) {
            return $this->errorResponse('CDN_NOT_CONFIGURED', 'api.error.cdn.not_configured', null, 409);
        }

        DB::transaction(function () use ($row, $provider): void {
            $row->forceFill([
                'integration_provider_id' => $provider->id,
                'driver' => $provider->driver,
                'status' => CdnPurgeStatus::PENDING,
                'attempts' => 0,
                'available_at' => null,
                'completed_at' => null,
                'error_code' => null,
                'error_message' => null,
            ])->save();

            $this->audit->succeeded(AuditAction::CDN_PURGE_RETRIED, $row->id, [
                'kind' => $row->kind->value,
                'item_count' => $row->item_count,
            ]);
        });

        ProcessCdnPurgeRequest::dispatch($row->id)->afterCommit();

        return $this->successResponse(
            (new CdnPurgeRequestResource($row->refresh()))->resolve($request),
            'api.cdn.purge_requeued',
            202,
        );
    }

    private function refuseEverything(StoreCdnPurgeRequest $request, IntegrationProvider $provider): ?JsonResponse
    {
        if (! $this->mayPurgeEverything($request)) {
            return $this->errorResponse('FORBIDDEN', 'api.error.cdn.purge_everything_forbidden', null, 403);
        }

        $phrase = $this->edge->confirmationPhrase($provider);

        if ($phrase === null) {
            return $this->errorResponse('CDN_NOT_VERIFIED', 'api.error.cdn.not_verified', null, 409);
        }

        if (trim((string) $request->validated('confirm')) !== $phrase) {
            return $this->errorResponse('CDN_CONFIRMATION_MISMATCH', 'api.error.cdn.confirmation_mismatch', null, 422);
        }

        $key = 'cdn-purge-everything:'.(string) $request->user()?->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, self::PURGE_EVERYTHING_PER_HOUR)) {
            $retryAfter = RateLimiter::availableIn($key);

            return $this->errorResponse('TOO_MANY_ATTEMPTS', 'api.error.too_many_attempts', ['retry_after' => $retryAfter], 429, ['seconds' => $retryAfter]);
        }

        RateLimiter::hit($key, 3600);

        return null;
    }

    private function mayPurgeEverything(Request $request): bool
    {
        return in_array('cdn.purge_everything', $this->grants->permissionsFor($request->user()), true);
    }

    /**
     * @return array{scope_name: string|null, scope_status: string|null, plan: string|null, verified_at: string|null, error_code: string|null, error_message: string|null}|null
     */
    private function verification(IntegrationProvider $provider): ?array
    {
        $settings = $provider->settings ?? [];

        if (! isset($settings['verified_at'])) {
            return null;
        }

        return [
            'scope_name' => $this->stringOrNull($settings['detected_scope_name'] ?? null),
            'scope_status' => $this->stringOrNull($settings['detected_scope_status'] ?? null),
            'plan' => $this->stringOrNull($settings['detected_plan'] ?? null),
            'verified_at' => $this->stringOrNull($settings['verified_at']),
            'error_code' => $this->stringOrNull($settings['detected_error_code'] ?? null),
            'error_message' => $this->stringOrNull($settings['detected_error_message'] ?? null),
        ];
    }

    /**
     * @return array{status: string, at: string, error_code: string|null, error_message: string|null}|null
     */
    private function lastAttempt(): ?array
    {
        /** @var IntegrationUsageLog|null $last */
        $last = IntegrationUsageLog::query()
            ->where('capability', IntegrationCapability::CDN->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $last === null ? null : [
            'status' => $last->status->value,
            'at' => $last->created_at?->toIso8601String() ?? '',
            'error_code' => $last->error_code,
            'error_message' => $last->error_message,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
