<?php

use App\Modules\Integration\Jobs\PruneCdnPurgeRequests;
use App\Modules\Integration\Jobs\PruneIntegrationUsageLogs;
use App\Modules\Integration\Jobs\SweepCdnPurgeRequests;
use App\Modules\Media\Jobs\PurgeDeletedMedia;
use App\Modules\Media\Jobs\SweepMediaAnalyses;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * What the scheduler container runs (ADR 0051 §6).
 *
 * Each retention window is read when the task runs rather than when this file loads:
 * this file is read by every artisan command, including a migration against a database
 * that has no settings table yet.
 *
 * Audit archival is deliberately absent. It is triggered by a person (ADR 0037).
 */
$retention = static function (string $reference, int $fallback): int {
    $configured = setting($reference, $fallback);

    return is_int($configured) && $configured > 0 ? $configured : $fallback;
};

Schedule::call(static function () use ($retention): void {
    PurgeDeletedMedia::dispatch($retention('operations.media_retention_days', 30));
})->daily()->name('media:purge-deleted');

Schedule::call(static function () use ($retention): void {
    PruneIntegrationUsageLogs::dispatch($retention('operations.integration_usage_retention_days', 90));
})->daily()->name('integrations:prune-usage');

// Edge invalidations the queue lost, and finished ones past the retention window (ADR 0053).
Schedule::job(new SweepCdnPurgeRequests)->everyFiveMinutes()->name('cdn:sweep-purges');

Schedule::call(static function () use ($retention): void {
    PruneCdnPurgeRequests::dispatch($retention('operations.integration_usage_retention_days', 90));
})->daily()->name('cdn:prune-purges');

// Media analyses a dead worker or a lost dispatch left behind (ADR 0054). Recovery only:
// nothing here requests an analysis.
Schedule::job(new SweepMediaAnalyses)->everyFiveMinutes()->name('media:sweep-analyses');

Schedule::command('auth:prune-codes')->daily();

Schedule::command('auth:clear-resets')->daily();
