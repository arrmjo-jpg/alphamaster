<?php

declare(strict_types=1);

namespace App\Modules\Media\Services\Analysis;

use App\Modules\Core\MediaAnalysis\AnalyzerDescriptor;
use App\Modules\Core\MediaAnalysis\Events\MediaAnalysisFinished;
use App\Modules\Core\MediaAnalysis\MediaAnalysisAvailability;
use App\Modules\Core\MediaAnalysis\MediaAnalysisContract;
use App\Modules\Core\MediaAnalysis\MediaAnalysisOutcome;
use App\Modules\Core\MediaAnalysis\MediaAnalysisRequest;
use App\Modules\Core\MediaAnalysis\MediaAnalysisResult;
use App\Modules\Core\MediaAnalysis\MediaAnalysisStatus;
use App\Modules\Core\MediaAnalysis\MediaAnalysisTicket;
use App\Modules\Core\MediaAnalysis\MediaAnalyzerContract;
use App\Modules\Media\Jobs\RunMediaAnalysis;
use App\Modules\Media\Models\MediaAnalysis;
use App\Modules\Media\Models\MediaFile;
use Illuminate\Support\Facades\DB;

/**
 * Media analysis, as Media carries it out (ADR 0054).
 *
 * Validates a consumer's request against the platform's state and limits, records it, and
 * queues it. Nothing here runs an analysis on its own initiative, and nothing calls a vendor:
 * the queued job does, through `MediaAnalyzerContract`.
 *
 * Deduplication is by fingerprint — the file's checksum, the types, the analyzer, its model
 * and the policy version — so asking again for the same analysis of the same content returns
 * the existing one instead of paying for it twice. A consumer that means to run it again says
 * so, and the new analysis supersedes the old one when it finishes.
 */
class MediaAnalysisService implements MediaAnalysisContract
{
    private const REUSABLE = [
        MediaAnalysisStatus::PENDING,
        MediaAnalysisStatus::PROCESSING,
        MediaAnalysisStatus::COMPLETED,
        MediaAnalysisStatus::INCONCLUSIVE,
    ];

    public function __construct(
        private readonly MediaAnalyzerContract $analyzer,
        private readonly MediaAnalysisPolicy $policy,
    ) {}

    public function availability(): MediaAnalysisAvailability
    {
        if (! $this->policy->enabled()) {
            return MediaAnalysisAvailability::unavailable(MediaAnalysisAvailability::DISABLED);
        }

        $descriptor = $this->descriptor();

        return $descriptor === null
            ? MediaAnalysisAvailability::unavailable(MediaAnalysisAvailability::NOT_CONFIGURED)
            : MediaAnalysisAvailability::available($descriptor->supportedTypes);
    }

    public function request(MediaAnalysisRequest $request): MediaAnalysisTicket
    {
        // Switched off: refused before anything is read, recorded or sent.
        if (! $this->policy->enabled()) {
            return new MediaAnalysisTicket(MediaAnalysisOutcome::DISABLED);
        }

        $descriptor = $this->descriptor();

        if ($descriptor === null) {
            return new MediaAnalysisTicket(MediaAnalysisOutcome::NOT_CONFIGURED);
        }

        $media = MediaFile::query()->find($request->mediaId);

        if ($media === null) {
            return new MediaAnalysisTicket(MediaAnalysisOutcome::MEDIA_NOT_FOUND);
        }

        if (! $media->isServable()) {
            return new MediaAnalysisTicket(MediaAnalysisOutcome::MEDIA_NOT_READY);
        }

        if (! $descriptor->accepts($media->mime_type)) {
            return new MediaAnalysisTicket(MediaAnalysisOutcome::UNSUPPORTED_MEDIA, detail: 'mime_type');
        }

        $requested = $request->typeValues();
        $supported = array_values(array_filter($requested, static fn (string $type): bool => $descriptor->supports($type)));
        $unsupported = array_values(array_diff($requested, $supported));

        if ($supported === []) {
            return new MediaAnalysisTicket(MediaAnalysisOutcome::UNSUPPORTED_TYPES, unsupportedTypes: $unsupported);
        }

        $limit = $this->exceededLimit($media, $descriptor);

        if ($limit !== null) {
            return new MediaAnalysisTicket(MediaAnalysisOutcome::LIMIT_EXCEEDED, unsupportedTypes: $unsupported, detail: $limit);
        }

        // Read from the duration intake recorded; the file is not probed again here.
        $duration = $this->policy->durationVerdict($media, $descriptor->maxDurationSeconds);

        if ($duration !== null) {
            return match ($duration) {
                MediaAnalysisOutcome::DURATION_TOO_SHORT => new MediaAnalysisTicket(
                    $duration,
                    unsupportedTypes: $unsupported,
                    detail: 'min_duration',
                    limitSeconds: $this->policy->minDurationSeconds(),
                ),
                MediaAnalysisOutcome::DURATION_TOO_LONG => new MediaAnalysisTicket(
                    $duration,
                    unsupportedTypes: $unsupported,
                    detail: 'max_duration',
                    limitSeconds: $this->policy->effectiveMaxDurationSeconds($descriptor->maxDurationSeconds),
                ),
                default => new MediaAnalysisTicket($duration, unsupportedTypes: $unsupported, detail: 'duration'),
            };
        }

        sort($supported);
        $policyVersion = $this->policy->version();
        $fingerprint = hash('sha256', (string) json_encode([
            $media->checksum,
            $supported,
            $descriptor->provider,
            $descriptor->analyzer,
            $descriptor->modelVersion,
            $policyVersion,
        ]));

        return DB::transaction(function () use ($request, $media, $descriptor, $supported, $unsupported, $policyVersion, $fingerprint): MediaAnalysisTicket {
            // Serialises requests for one file, so two identical requests arriving together
            // cannot both find nothing to reuse and both pay for an analysis.
            MediaFile::query()->whereKey($media->id)->lockForUpdate()->first();

            if (! $request->reanalyze) {
                $existing = MediaAnalysis::query()
                    ->where('media_file_id', $media->id)
                    ->where('input_fingerprint', $fingerprint)
                    ->whereNull('superseded_by')
                    ->whereIn('status', array_map(static fn (MediaAnalysisStatus $status): string => $status->value, self::REUSABLE))
                    ->orderByDesc('created_at')
                    ->first();

                if ($existing !== null) {
                    return new MediaAnalysisTicket(MediaAnalysisOutcome::REUSED, $existing->id, $unsupported);
                }
            }

            $daily = $this->policy->dailyLimit();

            if ($daily !== null && MediaAnalysis::query()->where('created_at', '>=', now()->startOfDay())->count() >= $daily) {
                return new MediaAnalysisTicket(MediaAnalysisOutcome::LIMIT_EXCEEDED, unsupportedTypes: $unsupported, detail: 'daily_limit');
            }

            $requestedBy = auth()->user()?->getAuthIdentifier();

            $analysis = MediaAnalysis::query()->create([
                'media_file_id' => $media->id,
                'consumer' => $request->consumer,
                'types' => $supported,
                'unsupported_types' => $unsupported,
                'status' => MediaAnalysisStatus::PENDING,
                'provider' => $descriptor->provider,
                'analyzer' => $descriptor->analyzer,
                'model_version' => $descriptor->modelVersion,
                'policy_version' => $policyVersion,
                'input_fingerprint' => $fingerprint,
                'reason' => $request->reason,
                'requested_by' => is_string($requestedBy) ? $requestedBy : null,
                'reanalysis_of' => $request->reanalyze ? $this->currentAnalysisOf($media->id, $supported)?->id : null,
            ]);

            RunMediaAnalysis::dispatch($analysis->id, $this->policy->timeoutSeconds())->afterCommit();

            return new MediaAnalysisTicket(MediaAnalysisOutcome::QUEUED, $analysis->id, $unsupported);
        });
    }

    public function find(string $analysisId): ?MediaAnalysisResult
    {
        return MediaAnalysis::query()->find($analysisId)?->toResult();
    }

    public function latest(string $mediaId, ?string $consumer = null): ?MediaAnalysisResult
    {
        return MediaAnalysis::query()
            ->where('media_file_id', $mediaId)
            ->whereNull('superseded_by')
            ->when($consumer !== null, static fn ($query) => $query->where('consumer', $consumer))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first()
            ?->toResult();
    }

    public function history(string $mediaId): array
    {
        return MediaAnalysis::query()
            ->where('media_file_id', $mediaId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (MediaAnalysis $analysis): MediaAnalysisResult => $analysis->toResult())
            ->values()
            ->all();
    }

    public function cancel(string $analysisId): bool
    {
        $analysis = MediaAnalysis::query()->find($analysisId);

        if ($analysis === null) {
            return false;
        }

        $withdrawn = MediaAnalysis::query()
            ->whereKey($analysisId)
            ->where('status', MediaAnalysisStatus::PENDING->value)
            ->update([
                'status' => MediaAnalysisStatus::CANCELLED->value,
                'error_code' => 'CANCELLED',
                'available_at' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]) === 1;

        if ($withdrawn) {
            MediaAnalysisFinished::dispatch($analysis->id, $analysis->media_file_id, $analysis->consumer, MediaAnalysisStatus::CANCELLED);
        }

        return $withdrawn;
    }

    private function descriptor(): ?AnalyzerDescriptor
    {
        return $this->analyzer->isConfigured() ? $this->analyzer->descriptor() : null;
    }

    /**
     * Which size limit this file exceeds, or null. The stricter of the operator's limit and
     * the analyzer's applies. Duration has outcomes of its own; see the policy.
     */
    private function exceededLimit(MediaFile $media, AnalyzerDescriptor $descriptor): ?string
    {
        $maxBytes = $this->stricter($this->policy->maxBytes(), $descriptor->maxBytes);

        if ($maxBytes !== null && $media->size_bytes > $maxBytes) {
            return 'max_file_size';
        }

        return null;
    }

    private function stricter(?int $platform, ?int $analyzer): ?int
    {
        $limits = array_filter([$platform, $analyzer], static fn (?int $limit): bool => $limit !== null);

        return $limits === [] ? null : min($limits);
    }

    /**
     * The current assessment of the same types for this file, which a re-analysis supersedes
     * once it finishes.
     *
     * @param  list<string>  $types
     */
    private function currentAnalysisOf(string $mediaId, array $types): ?MediaAnalysis
    {
        return MediaAnalysis::query()
            ->where('media_file_id', $mediaId)
            ->whereNull('superseded_by')
            ->whereIn('status', [MediaAnalysisStatus::COMPLETED->value, MediaAnalysisStatus::INCONCLUSIVE->value])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->first(static function (MediaAnalysis $analysis) use ($types): bool {
                $existing = $analysis->types;
                sort($existing);

                return $existing === $types;
            });
    }
}
