<?php

declare(strict_types=1);

namespace App\Modules\Integration\Jobs;

use App\Modules\Integration\Enums\CdnPurgeStatus;
use App\Modules\Integration\Models\CdnPurgeRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes finished purge records older than the integration retention window (ADR 0053 §4).
 *
 * Only finished ones. A pending or processing row is work still owed to the edge, and a
 * failed row younger than the window is the evidence an operator needs; deleting either
 * early would erase exactly the state ADR 0036 requires to stay visible.
 */
class PruneCdnPurgeRequests implements ShouldQueue
{
    use Queueable;

    private const BATCH = 1000;

    public int $tries = 3;

    public function __construct(public readonly int $retentionDays = 90)
    {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        $cutoff = now()->subDays(max(1, $this->retentionDays));

        do {
            $ids = CdnPurgeRequest::query()
                ->whereIn('status', [CdnPurgeStatus::SUCCEEDED->value, CdnPurgeStatus::FAILED->value])
                ->where('completed_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            CdnPurgeRequest::query()->whereIn('id', $ids->all())->delete();
        } while ($ids->count() === self::BATCH);
    }
}
