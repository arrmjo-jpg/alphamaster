<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Core\MediaAnalysis\MediaAnalysisStatus;
use App\Modules\Media\Models\MediaAnalysis;
use App\Modules\Media\Services\Analysis\MediaAnalysisPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recovers analyses the queue lost (ADR 0054).
 *
 * A worker that died mid-analysis leaves a row `processing` forever, and a dispatch that
 * never reached the queue leaves one `pending` with nothing to run it. Either would look
 * like work in progress to a consumer waiting on it. This puts the first back to `pending`
 * and dispatches both again; the claim in the job makes a duplicate dispatch harmless.
 */
class SweepMediaAnalyses implements ShouldQueue
{
    use Queueable;

    private const LOST_AFTER_MINUTES = 2;

    private const BATCH = 200;

    public function __construct()
    {
        $this->onQueue('media-analysis');

        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-media-analysis');
        }
    }

    public function handle(MediaAnalysisPolicy $policy): void
    {
        $timeout = $policy->timeoutSeconds();

        MediaAnalysis::query()
            ->where('status', MediaAnalysisStatus::PROCESSING->value)
            ->where('started_at', '<', now()->subSeconds($timeout + 300))
            ->update([
                'status' => MediaAnalysisStatus::PENDING->value,
                'available_at' => null,
                'updated_at' => now()->subMinutes(self::LOST_AFTER_MINUTES + 1),
            ]);

        MediaAnalysis::query()
            ->where('status', MediaAnalysisStatus::PENDING->value)
            ->where('updated_at', '<', now()->subMinutes(self::LOST_AFTER_MINUTES))
            ->where(static function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('created_at')
            ->limit(self::BATCH)
            ->pluck('id')
            ->each(static fn (string $id) => RunMediaAnalysis::dispatch($id, $timeout));
    }
}
