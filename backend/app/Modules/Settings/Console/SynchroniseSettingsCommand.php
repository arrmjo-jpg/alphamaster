<?php

declare(strict_types=1);

namespace App\Modules\Settings\Console;

use App\Modules\Settings\Definitions\SettingSynchronizer;
use Illuminate\Console\Command;

/**
 * Bring the settings table into line with the registry.
 *
 * Safe to run on every deploy: it is idempotent, it never writes over a configured
 * value, and it never deletes a row. What it cannot decide, it reports.
 */
class SynchroniseSettingsCommand extends Command
{
    protected $signature = 'settings:sync
                            {--strict : Exit non-zero when orphans or conflicts are reported}';

    protected $description = 'Materialise setting definitions into rows, without overwriting configured values';

    public function handle(SettingSynchronizer $synchronizer): int
    {
        $report = $synchronizer->synchronise();

        $this->line(sprintf(
            'created %d, updated %d, unchanged %d',
            count($report->createdReferences()),
            count($report->updatedReferences()),
            count($report->unchangedReferences()),
        ));

        foreach ($report->createdReferences() as $reference) {
            $this->line('  + '.$reference);
        }

        foreach ($report->updatedReferences() as $reference) {
            $this->line('  ~ '.$reference);
        }

        // Orphans and conflicts are not failures: the run completed and the table is
        // consistent. They are decisions only an operator can take, so they are shown
        // rather than acted on. A configured value — a credential above all — is never
        // removed because a definition disappeared (ADR 0018).
        if ($report->orphanedReferences() !== []) {
            $this->newLine();
            $this->warn('Orphaned settings — no definition declares these. Values are kept, nothing was deleted:');

            foreach ($report->orphanedReferences() as $reference) {
                $this->line('  ? '.$reference);
            }
        }

        if ($report->conflictReferences() !== []) {
            $this->newLine();
            $this->warn('Conflicts — the declaration and the stored value disagree, so the row was left alone:');

            foreach ($report->conflictReferences() as $reference => $reason) {
                $this->line('  ! '.$reference.' — '.$reason);
            }
        }

        if ($this->option('strict') && $report->needsAttention()) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
