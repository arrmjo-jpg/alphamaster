<?php

declare(strict_types=1);

namespace App\Modules\Integration\Jobs;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Delivery\EdgeInvalidationKind;
use App\Modules\Integration\Data\CdnPurgeResult;
use App\Modules\Integration\Enums\CdnPurgeStatus;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Models\CdnPurgeRequest;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Integration\Services\CdnEdgeCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Sends one recorded purge to the CDN, and writes down what happened (ADR 0053 §4).
 *
 * The row is the state, not the job. A job is claimed by moving its row from `pending` to
 * `processing` in one conditional update, so two workers — a retry and a sweep, say —
 * cannot both call the vendor. Retries are rows put back to `pending` with a time, and a
 * fresh job delayed to it: the queue's own retry would forget why it retried and would
 * hide the attempt count an operator reads.
 *
 * * A **rate limit before calling** (the plan's budget, spent here) defers without counting
 *   an attempt.
 * * A **retryable vendor failure** — a 429, an outage, a connection that never opened — is
 *   deferred by the vendor's `Retry-After` when it gave one, otherwise by a doubling backoff,
 *   up to five attempts.
 * * Anything else, or the fifth failure, is **final**: the row says `failed`, with the
 *   vendor's code and message, until an operator retries it.
 *
 * A purge is never reported as succeeded unless the vendor accepted it.
 */
class ProcessCdnPurgeRequest implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 5;

    private const FIRST_BACKOFF_SECONDS = 30;

    private const MAX_BACKOFF_SECONDS = 900;

    /** The row carries the retries; the queue must not add its own. */
    public int $tries = 1;

    public int $timeout = 60;

    private ?AuditRecorderContract $audit = null;

    public function __construct(public readonly string $requestId)
    {
        $this->onQueue('integrations');
    }

    public function handle(CdnEdgeCache $edge, ?AuditRecorderContract $audit = null): void
    {
        $this->audit = $audit ?? app(AuditRecorderContract::class);

        if (! $this->claim()) {
            return;
        }

        /** @var CdnPurgeRequest $row */
        $row = CdnPurgeRequest::query()->with('provider')->findOrFail($this->requestId);
        $provider = $row->provider;

        if ($provider === null || ! $provider->is_active || $edge->missingConfiguration($provider) !== []) {
            $this->fail_($row, CdnPurgeResult::failure(
                'CDN_NOT_CONFIGURED',
                'The CDN provider this purge was queued for is no longer active and configured.',
                retryable: false,
            ));

            return;
        }

        $driver = $edge->driverFor($provider);
        $limits = $driver->limits($provider);

        try {
            $invalidation = EdgeInvalidation::of($row->kind, $row->items);
        } catch (Throwable $e) {
            $this->fail_($row, CdnPurgeResult::failure('CDN_REQUEST_INVALID', $e->getMessage(), retryable: false));

            return;
        }

        $perMinute = $limits->requestsPerMinute($row->kind);

        if ($perMinute !== null) {
            $key = 'cdn-purge:'.$provider->id.':'.$limits->bucket($row->kind);

            if (RateLimiter::tooManyAttempts($key, $perMinute)) {
                $this->defer($row, max(1, RateLimiter::availableIn($key)), null, countsAsAttempt: false);

                return;
            }

            RateLimiter::hit($key, 60);
        }

        $startedAt = hrtime(true);

        try {
            $result = $driver->purge($invalidation, $provider);
        } catch (Throwable $e) {
            $result = CdnPurgeResult::failure('CDN_DRIVER_ERROR', $e->getMessage(), retryable: true);
        }

        IntegrationUsageLog::query()->create([
            'integration_provider_id' => $provider->id,
            'capability' => $provider->capability,
            'driver' => $provider->driver,
            'status' => $result->successful ? UsageStatus::SUCCESS : UsageStatus::FAILURE,
            'reference' => $result->reference,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'units' => $row->item_count,
        ]);

        if ($result->successful) {
            $row->forceFill([
                'status' => CdnPurgeStatus::SUCCEEDED,
                'provider_reference' => $result->reference,
                'error_code' => null,
                'error_message' => null,
                'available_at' => null,
                'completed_at' => now(),
            ])->save();

            $this->recordOutcome($row, $result);

            return;
        }

        if ($result->retryable && $row->attempts < self::MAX_ATTEMPTS) {
            $this->defer($row, $result->retryAfterSeconds ?? self::backoff($row->attempts), $result);

            return;
        }

        $this->fail_($row, $result);
    }

    /**
     * A worker that died mid-call leaves the row `processing`. The sweep recovers those;
     * this records a failure the queue itself reported.
     */
    public function failed(?Throwable $exception): void
    {
        CdnPurgeRequest::query()
            ->whereKey($this->requestId)
            ->where('status', CdnPurgeStatus::PROCESSING->value)
            ->update([
                'status' => CdnPurgeStatus::FAILED->value,
                'error_code' => 'CDN_WORKER_FAILED',
                'error_message' => mb_substr((string) $exception?->getMessage(), 0, 500),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public static function backoff(int $attempt): int
    {
        return min(self::FIRST_BACKOFF_SECONDS * (2 ** max(0, $attempt - 1)), self::MAX_BACKOFF_SECONDS);
    }

    private function claim(): bool
    {
        return CdnPurgeRequest::query()
            ->whereKey($this->requestId)
            ->where('status', CdnPurgeStatus::PENDING->value)
            ->where(static function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->update([
                'status' => CdnPurgeStatus::PROCESSING->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]) === 1;
    }

    private function defer(CdnPurgeRequest $row, int $seconds, ?CdnPurgeResult $result, bool $countsAsAttempt = true): void
    {
        $row->forceFill([
            'status' => CdnPurgeStatus::PENDING,
            'available_at' => now()->addSeconds($seconds),
            'attempts' => $countsAsAttempt ? $row->attempts : max(0, $row->attempts - 1),
            'error_code' => $result->errorCode ?? $row->error_code,
            'error_message' => $result->errorMessage ?? $row->error_message,
        ])->save();

        self::dispatch($row->id)->delay(now()->addSeconds($seconds));
    }

    private function fail_(CdnPurgeRequest $row, CdnPurgeResult $result): void
    {
        $row->forceFill([
            'status' => CdnPurgeStatus::FAILED,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'available_at' => null,
            'completed_at' => now(),
        ])->save();

        $this->recordOutcome($row, $result);
    }

    /**
     * A purge of everything is an incident action, so what the vendor finally answered goes
     * into the audit trail beside who asked (ADR 0036). Named purges are not audited here:
     * automatic invalidations would bury the trail, and their outcome is on the request row.
     */
    private function recordOutcome(CdnPurgeRequest $row, CdnPurgeResult $result): void
    {
        if ($row->kind !== EdgeInvalidationKind::EVERYTHING || $this->audit === null) {
            return;
        }

        $context = [
            'request' => $row->id,
            'requested_by' => $row->requested_by,
            'provider' => $row->driver,
            'attempts' => $row->attempts,
            'provider_reference' => $result->reference,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
        ];

        $result->successful
            ? $this->audit->succeeded(AuditAction::CDN_PURGE_EVERYTHING_COMPLETED, $row->id, $context)
            : $this->audit->failed(AuditAction::CDN_PURGE_EVERYTHING_COMPLETED, $row->id, $context);
    }
}
