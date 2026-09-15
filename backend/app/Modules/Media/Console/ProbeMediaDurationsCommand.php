<?php

declare(strict_types=1);

namespace App\Modules\Media\Console;

use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Jobs\ProbeMediaDuration;
use App\Modules\Media\Models\MediaFile;
use Illuminate\Console\Command;

/**
 * Queues a duration reading for ready video and audio that has none (ADR 0054).
 *
 * Run once after deploying the image that installs ffprobe, so files uploaded before then
 * can be checked against the analysis duration limits. Until a file has a reading, a request
 * to analyse it is refused as `duration_unavailable`. Safe to run again: a file that already
 * has a reading, or a recorded reason there is none, is skipped.
 */
class ProbeMediaDurationsCommand extends Command
{
    protected $signature = 'media:probe-durations';

    protected $description = 'Queue a duration reading for ready video and audio files that have none';

    public function handle(): int
    {
        $queued = 0;

        MediaFile::query()
            ->where('status', MediaStatus::READY->value)
            ->whereIn('type', [MediaType::VIDEO->value, MediaType::AUDIO->value])
            ->orderBy('id')
            ->chunkById(200, function ($files) use (&$queued): void {
                foreach ($files as $media) {
                    /** @var MediaFile $media */
                    if (! array_key_exists('duration_available', $media->metadata ?? [])) {
                        ProbeMediaDuration::dispatch($media->id);
                        $queued++;
                    }
                }
            });

        $this->info("Queued {$queued} file(s) for a duration reading.");

        return self::SUCCESS;
    }
}
