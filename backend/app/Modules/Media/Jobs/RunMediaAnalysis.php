<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Core\MediaAnalysis\AnalyzerOutcome;
use App\Modules\Core\MediaAnalysis\Events\MediaAnalysisFinished;
use App\Modules\Core\MediaAnalysis\MediaAnalysisClassification;
use App\Modules\Core\MediaAnalysis\MediaAnalysisInput;
use App\Modules\Core\MediaAnalysis\MediaAnalysisStatus;
use App\Modules\Core\MediaAnalysis\MediaAnalyzerContract;
use App\Modules\Media\Contracts\MediaStorageContract;
use App\Modules\Media\Models\MediaAnalysis;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Services\Analysis\MediaAnalysisPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one recorded analysis (ADR 0054).
 *
 * On its own queue, `media-analysis`, so a slow analyzer never delays media intake or
 * notifications. The row is the state: the job claims it with one conditional update, so a
 * retry and a sweep cannot both send the same file. Retries are the row going back to
 * `pending` with a time, never the queue's own, so the attempt count an operator reads is
 * the real one.
 *
 * The capability and the file are checked again when the job runs, because both can change
 * while it waits: an operator switching analysis off cancels what has not started, and so
 * does the file being removed. Neither is reported as a failure of the analyzer.
 */
class RunMediaAnalysis implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    private const FIRST_BACKOFF_SECONDS = 30;

    private const MAX_BACKOFF_SECONDS = 600;

    /** The row carries the retries; the queue must not add its own. */
    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly string $analysisId, int $timeoutSeconds = 300)
    {
        $this->timeout = max(30, $timeoutSeconds);
        $this->onQueue('media-analysis');

        // On Redis, its own connection: the shared one hands a job to another worker after
        // 90 seconds, which for an analysis still running would mark it failed underneath the
        // worker doing it. Other drivers keep the default connection.
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-media-analysis');
        }
    }

    public function handle(MediaAnalyzerContract $analyzer, MediaAnalysisPolicy $policy, MediaStorageContract $storage): void
    {
        if (! $this->claim()) {
            return;
        }

        /** @var MediaAnalysis $analysis */
        $analysis = MediaAnalysis::query()->findOrFail($this->analysisId);

        if (! $policy->enabled()) {
            $this->finish($analysis, MediaAnalysisStatus::CANCELLED, 'ANALYSIS_DISABLED', 'Media analysis was switched off before this analysis ran.');

            return;
        }

        $descriptor = $analyzer->isConfigured() ? $analyzer->descriptor() : null;

        if ($descriptor === null) {
            $this->finish($analysis, MediaAnalysisStatus::FAILED, 'NOT_CONFIGURED', 'No media analyzer is active and configured.');

            return;
        }

        $media = MediaFile::query()->find($analysis->media_file_id);

        if ($media === null || ! $media->isServable()) {
            $this->finish($analysis, MediaAnalysisStatus::CANCELLED, 'MEDIA_UNAVAILABLE', 'The media is no longer available to analyse.');

            return;
        }

        // The limits may have been tightened while this waited. Checked before the file is
        // handed to the analyzer, never after.
        $duration = $policy->durationVerdict($media, $descriptor->maxDurationSeconds);

        if ($duration !== null) {
            $this->finish($analysis, MediaAnalysisStatus::CANCELLED, strtoupper($duration->value), 'The media no longer meets the duration limits for analysis.');

            return;
        }

        $input = new MediaAnalysisInput(
            analysisId: $analysis->id,
            mediaId: $media->id,
            mimeType: $media->mime_type,
            sizeBytes: $media->size_bytes,
            durationSeconds: $media->duration_seconds,
            checksum: $media->checksum,
            types: $analysis->types,
            stream: static fn () => $storage->readStream($media->path, $media->disk),
            temporaryUrl: static fn (int $ttl): ?string => $storage->temporaryUrl($media->path, $media->disk, $ttl),
        );

        try {
            $outcome = $analyzer->analyze($input);
        } catch (Throwable $e) {
            $outcome = AnalyzerOutcome::failure('ANALYZER_ERROR', $e->getMessage(), retryable: true);
        }

        if (! $outcome->successful) {
            if ($outcome->retryable && $analysis->attempts < self::MAX_ATTEMPTS) {
                $this->defer($analysis, $outcome);

                return;
            }

            $this->finish($analysis, MediaAnalysisStatus::FAILED, $outcome->errorCode, $outcome->errorMessage);

            return;
        }

        $scores = $this->validScores($outcome->scores, $analysis->types);

        if ($scores === null) {
            $this->finish($analysis, MediaAnalysisStatus::FAILED, 'ANALYZER_INVALID_RESULT', 'The analyzer returned a score outside 0 to 1, or for a type that was not requested.');

            return;
        }

        $this->record($analysis, $outcome, $scores, $policy);
    }

    /**
     * A worker that died mid-analysis leaves the row `processing`; the sweep recovers those.
     * This records a failure the queue itself reported.
     */
    public function failed(?Throwable $exception): void
    {
        $analysis = MediaAnalysis::query()->find($this->analysisId);

        if ($analysis === null || $analysis->status !== MediaAnalysisStatus::PROCESSING) {
            return;
        }

        $this->finish($analysis, MediaAnalysisStatus::FAILED, 'WORKER_FAILED', (string) $exception?->getMessage());
    }

    public static function backoff(int $attempt): int
    {
        return min(self::FIRST_BACKOFF_SECONDS * (2 ** max(0, $attempt - 1)), self::MAX_BACKOFF_SECONDS);
    }

    private function claim(): bool
    {
        return MediaAnalysis::query()
            ->whereKey($this->analysisId)
            ->where('status', MediaAnalysisStatus::PENDING->value)
            ->where(static function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->update([
                'status' => MediaAnalysisStatus::PROCESSING->value,
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * @param  array<string, float>  $scores
     */
    private function record(MediaAnalysis $analysis, AnalyzerOutcome $outcome, array $scores, MediaAnalysisPolicy $policy): void
    {
        $requested = $analysis->types;

        // A requested type the analyzer neither scored nor declared unsupported is still
        // unassessed, and is reported as unsupported rather than silently dropped.
        $unassessed = array_values(array_diff($requested, array_keys($scores)));
        $unsupported = array_values(array_unique([
            ...($analysis->unsupported_types ?? []),
            ...array_values(array_intersect($outcome->unsupportedTypes, $requested)),
            ...$unassessed,
        ]));

        $status = match (true) {
            $outcome->inconclusive => MediaAnalysisStatus::INCONCLUSIVE,
            $scores === [] => MediaAnalysisStatus::UNSUPPORTED,
            default => MediaAnalysisStatus::COMPLETED,
        };

        $classification = match ($status) {
            MediaAnalysisStatus::COMPLETED => $policy->classify($scores),
            MediaAnalysisStatus::INCONCLUSIVE => MediaAnalysisClassification::INCONCLUSIVE,
            default => null,
        };

        $confidence = $outcome->confidence !== null && $outcome->confidence >= 0 && $outcome->confidence <= 1
            ? $outcome->confidence
            : null;

        DB::transaction(function () use ($analysis, $outcome, $scores, $unsupported, $status, $classification, $confidence, $policy): void {
            $analysis->forceFill([
                'status' => $status,
                'classification' => $classification,
                'confidence' => $confidence,
                'scores' => $scores,
                'unsupported_types' => $unsupported,
                'signals' => $outcome->signals,
                'model_version' => $outcome->modelVersion ?? $analysis->model_version,
                'policy_version' => $policy->version(),
                'provider_reference' => $outcome->reference,
                'error_code' => null,
                'error_message' => null,
                'available_at' => null,
                'completed_at' => now(),
            ])->save();

            // A re-analysis supersedes the assessment it re-ran only once it has one of its
            // own. The earlier row keeps everything it recorded; only the pointer is written.
            if ($status->isAssessment() && $analysis->reanalysis_of !== null) {
                MediaAnalysis::query()
                    ->whereKey($analysis->reanalysis_of)
                    ->whereNull('superseded_by')
                    ->update(['superseded_by' => $analysis->id, 'updated_at' => now()]);
            }

            MediaAnalysisFinished::dispatch($analysis->id, $analysis->media_file_id, $analysis->consumer, $status);
        });
    }

    private function defer(MediaAnalysis $analysis, AnalyzerOutcome $outcome): void
    {
        $seconds = max(1, $outcome->retryAfterSeconds ?? self::backoff($analysis->attempts));

        $analysis->forceFill([
            'status' => MediaAnalysisStatus::PENDING,
            'available_at' => now()->addSeconds($seconds),
            'error_code' => $outcome->errorCode,
            'error_message' => $outcome->errorMessage,
        ])->save();

        self::dispatch($analysis->id, $this->timeout)->delay(now()->addSeconds($seconds));
    }

    private function finish(MediaAnalysis $analysis, MediaAnalysisStatus $status, ?string $code, ?string $message): void
    {
        DB::transaction(function () use ($analysis, $status, $code, $message): void {
            $analysis->forceFill([
                'status' => $status,
                'classification' => null,
                'error_code' => $code,
                'error_message' => $message === null ? null : mb_substr($message, 0, 500),
                'available_at' => null,
                'completed_at' => now(),
            ])->save();

            MediaAnalysisFinished::dispatch($analysis->id, $analysis->media_file_id, $analysis->consumer, $status);
        });
    }

    /**
     * Scores keyed by a requested type and between 0 and 1, or null when any is not.
     *
     * @param  array<string, mixed>  $scores
     * @param  list<string>  $requested
     * @return array<string, float>|null
     */
    private function validScores(array $scores, array $requested): ?array
    {
        $valid = [];

        foreach ($scores as $type => $score) {
            if (! in_array((string) $type, $requested, true) || ! is_numeric($score) || $score < 0 || $score > 1) {
                return null;
            }

            $valid[(string) $type] = (float) $score;
        }

        return $valid;
    }
}
