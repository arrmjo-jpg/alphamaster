<?php

declare(strict_types=1);

namespace App\Modules\Integration\Jobs;

use App\Modules\Integration\Enums\CdnPurgeStatus;
use App\Modules\Integration\Models\CdnPurgeRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recovers purges the queue lost (ADR 0053 §4).
 *
 * Two ways a recorded purge can be forgotten: a worker dies between claiming a row and
 * finishing it, leaving it `processing`; or a dispatch never reaches the queue, leaving it
 * `pending` with nothing to run it. Either would leave stale content behind a row that
 * looks in progress. Every few minutes this puts the first back to `pending` and dispatches
 * both again. The claim in the processing job makes a duplicate dispatch harmless.
 */
class SweepCdnPurgeRequests implements ShouldQueue
{
    use Queueable;

    /** Longer than any single vendor call plus the job timeout. */
    private const STUCK_AFTER_MINUTES = 15;

    /** A dispatch younger than this is still on its way to a worker. */
    private const LOST_AFTER_MINUTES = 2;

    private const BATCH = 500;

    public function __construct()
    {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        CdnPurgeRequest::query()
            ->where('status', CdnPurgeStatus::PROCESSING->value)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->update([
                'status' => CdnPurgeStatus::PENDING->value,
                'available_at' => null,
                'updated_at' => now()->subMinutes(self::LOST_AFTER_MINUTES + 1),
            ]);

        CdnPurgeRequest::query()
            ->where('status', CdnPurgeStatus::PENDING->value)
            ->where('updated_at', '<', now()->subMinutes(self::LOST_AFTER_MINUTES))
            ->where(static function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('created_at')
            ->limit(self::BATCH)
            ->pluck('id')
            ->each(static fn (string $id) => ProcessCdnPurgeRequest::dispatch($id));
    }
}
