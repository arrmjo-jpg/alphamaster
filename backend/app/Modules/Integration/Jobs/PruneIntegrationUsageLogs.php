<?php

declare(strict_types=1);

namespace App\Modules\Integration\Jobs;

use App\Modules\Integration\Models\IntegrationUsageLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes vendor attempt records older than the retention window (ADR 0029 item 3,
 * ADR 0051 §6).
 *
 * These rows are diagnostics about vendors — which provider answered, how long it took,
 * what code it failed with — and they grow with every SMS, captcha check, AI call and
 * push. They are not the audit trail, which is never pruned on a schedule.
 *
 * Deleted in bounded batches by id, so a first run against a year of history does not
 * hold one enormous delete open.
 */
class PruneIntegrationUsageLogs implements ShouldQueue
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
            $ids = IntegrationUsageLog::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            IntegrationUsageLog::query()->whereIn('id', $ids->all())->delete();
        } while ($ids->count() === self::BATCH);
    }
}
