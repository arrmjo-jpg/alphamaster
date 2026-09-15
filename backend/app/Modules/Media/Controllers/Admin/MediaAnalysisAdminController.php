<?php

declare(strict_types=1);

namespace App\Modules\Media\Controllers\Admin;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\MediaAnalysis\MediaAnalysisContract;
use App\Modules\Core\MediaAnalysis\MediaAnalysisOutcome;
use App\Modules\Core\MediaAnalysis\MediaAnalysisRequest;
use App\Modules\Core\MediaAnalysis\MediaAnalysisStatus;
use App\Modules\Core\MediaAnalysis\MediaAnalyzerContract;
use App\Modules\Media\Models\MediaAnalysis;
use App\Modules\Media\Models\MediaAnalysisReview;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Requests\RequestMediaAnalysisRequest;
use App\Modules\Media\Requests\ReviewMediaAnalysisRequest;
use App\Modules\Media\Resources\MediaAnalysisResource;
use App\Modules\Media\Services\Analysis\MediaAnalysisPolicy;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Media analysis in the Admin (ADR 0054).
 *
 * The Admin is one consumer of the capability among any number, and nothing here makes it
 * special: a manual request goes through `MediaAnalysisContract` exactly as a module's does,
 * under the consumer name `admin.manual`. What the Admin adds is what an operator needs —
 * the capability's state, each file's analyses, withdrawing one, and recording a human review.
 *
 * Every response is authenticated and therefore `no-store` (ADR 0036); an analysis result
 * never reaches an edge.
 */
class MediaAnalysisAdminController extends BaseApiController
{
    public const CONSUMER = 'admin.manual';

    public function __construct(
        private readonly MediaAnalysisContract $analyses,
        private readonly MediaAnalyzerContract $analyzer,
        private readonly MediaAnalysisPolicy $policy,
        private readonly AuditRecorderContract $audit,
    ) {}

    /**
     * The capability's state: whether it is on and configured, what the analyzer accepts, the operator's limits and thresholds, and the queue.
     */
    #[Response(200, type: 'array{success: bool, data: array{available: bool, reason: string|null, supported_types: list<string>, analyzer: array{provider: string, analyzer: string, model_version: string|null, supported_types: list<string>, accepted_mime_types: list<string>, max_bytes: int|null, max_duration_seconds: int|null}|null, policy: array{enabled: bool, max_bytes: int|null, min_video_duration_seconds: int|null, max_video_duration_seconds: int|null, daily_limit: int|null, timeout_seconds: int, likely_synthetic_threshold: float|null, likely_authentic_threshold: float|null, version: string}, queue: array{pending: int, processing: int, failed_last_day: int, requested_today: int}}}')]
    public function status(): JsonResponse
    {
        $availability = $this->analyses->availability();
        $descriptor = $this->analyzer->isConfigured() ? $this->analyzer->descriptor() : null;

        return $this->successResponse([
            'available' => $availability->available,
            'reason' => $availability->reason,
            'supported_types' => $availability->supportedTypes,
            'analyzer' => $descriptor === null ? null : [
                'provider' => $descriptor->provider,
                'analyzer' => $descriptor->analyzer,
                'model_version' => $descriptor->modelVersion,
                'supported_types' => $descriptor->supportedTypes,
                'accepted_mime_types' => $descriptor->acceptedMimeTypes,
                'max_bytes' => $descriptor->maxBytes,
                'max_duration_seconds' => $descriptor->maxDurationSeconds,
            ],
            'policy' => [
                'enabled' => $this->policy->enabled(),
                'max_bytes' => $this->policy->maxBytes(),
                'min_video_duration_seconds' => $this->policy->minDurationSeconds(),
                'max_video_duration_seconds' => $this->policy->maxDurationSeconds(),
                'daily_limit' => $this->policy->dailyLimit(),
                'timeout_seconds' => $this->policy->timeoutSeconds(),
                'likely_synthetic_threshold' => $this->policy->syntheticThreshold(),
                'likely_authentic_threshold' => $this->policy->authenticThreshold(),
                'version' => $this->policy->version(),
            ],
            'queue' => [
                'pending' => MediaAnalysis::query()->where('status', MediaAnalysisStatus::PENDING->value)->count(),
                'processing' => MediaAnalysis::query()->where('status', MediaAnalysisStatus::PROCESSING->value)->count(),
                'failed_last_day' => MediaAnalysis::query()
                    ->where('status', MediaAnalysisStatus::FAILED->value)
                    ->where('completed_at', '>=', now()->subDay())
                    ->count(),
                'requested_today' => MediaAnalysis::query()->where('created_at', '>=', now()->startOfDay())->count(),
            ],
        ]);
    }

    /**
     * Every analysis of a media file, newest first, with its reviews.
     */
    #[Response(200, type: 'array{success: bool, data: list<MediaAnalysisResource>}')]
    public function index(MediaFile $media): JsonResponse
    {
        $rows = MediaAnalysis::query()
            ->where('media_file_id', $media->id)
            ->with('reviews.reviewer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return $this->successResponse(MediaAnalysisResource::collection($rows));
    }

    /**
     * Ask for an analysis of this file, as an operator.
     *
     * Answered 202 when an analysis was queued and 200 when an equivalent one already existed. Recorded in the audit trail when one is queued.
     */
    #[Response(202, type: 'array{success: bool, message: string, data: MediaAnalysisResource}')]
    #[Response(409, description: 'MEDIA_ANALYSIS_DISABLED, MEDIA_ANALYSIS_NOT_CONFIGURED or MEDIA_NOT_READY.')]
    #[Response(422, description: 'MEDIA_ANALYSIS_UNSUPPORTED_MEDIA, MEDIA_ANALYSIS_UNSUPPORTED_TYPES, MEDIA_ANALYSIS_LIMIT_EXCEEDED, MEDIA_ANALYSIS_DURATION_TOO_SHORT, MEDIA_ANALYSIS_DURATION_TOO_LONG or MEDIA_ANALYSIS_DURATION_UNAVAILABLE; details name the unsupported types, the limit, or the duration and the limit it failed.')]
    public function store(RequestMediaAnalysisRequest $request, MediaFile $media): JsonResponse
    {
        /** @var list<string> $types */
        $types = array_values((array) $request->validated('types'));
        $reanalyze = (bool) $request->validated('reanalyze', false);

        $ticket = $this->analyses->request(MediaAnalysisRequest::for(
            mediaId: $media->id,
            types: $types,
            consumer: self::CONSUMER,
            reanalyze: $reanalyze,
            reason: 'Requested from the Admin',
        ));

        if (! $ticket->accepted()) {
            return match ($ticket->outcome) {
                MediaAnalysisOutcome::DISABLED => $this->errorResponse('MEDIA_ANALYSIS_DISABLED', 'api.error.media_analysis.disabled', null, 409),
                MediaAnalysisOutcome::NOT_CONFIGURED => $this->errorResponse('MEDIA_ANALYSIS_NOT_CONFIGURED', 'api.error.media_analysis.not_configured', null, 409),
                MediaAnalysisOutcome::MEDIA_NOT_READY => $this->errorResponse('MEDIA_NOT_READY', 'api.error.media_analysis.media_not_ready', null, 409),
                MediaAnalysisOutcome::MEDIA_NOT_FOUND => $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404),
                MediaAnalysisOutcome::UNSUPPORTED_MEDIA => $this->errorResponse('MEDIA_ANALYSIS_UNSUPPORTED_MEDIA', 'api.error.media_analysis.unsupported_media', null, 422),
                MediaAnalysisOutcome::UNSUPPORTED_TYPES => $this->errorResponse('MEDIA_ANALYSIS_UNSUPPORTED_TYPES', 'api.error.media_analysis.unsupported_types', ['unsupported_types' => $ticket->unsupportedTypes], 422),
                MediaAnalysisOutcome::DURATION_TOO_SHORT => $this->errorResponse('MEDIA_ANALYSIS_DURATION_TOO_SHORT', 'api.error.media_analysis.duration_too_short', ['duration_ms' => $media->durationMilliseconds(), 'limit_seconds' => $ticket->limitSeconds], 422),
                MediaAnalysisOutcome::DURATION_TOO_LONG => $this->errorResponse('MEDIA_ANALYSIS_DURATION_TOO_LONG', 'api.error.media_analysis.duration_too_long', ['duration_ms' => $media->durationMilliseconds(), 'limit_seconds' => $ticket->limitSeconds], 422),
                MediaAnalysisOutcome::DURATION_UNAVAILABLE => $this->errorResponse('MEDIA_ANALYSIS_DURATION_UNAVAILABLE', 'api.error.media_analysis.duration_unavailable', null, 422),
                default => $this->errorResponse('MEDIA_ANALYSIS_LIMIT_EXCEEDED', 'api.error.media_analysis.limit_exceeded', ['limit' => $ticket->detail], 422),
            };
        }

        if ($ticket->outcome === MediaAnalysisOutcome::QUEUED) {
            $this->audit->succeeded(AuditAction::MEDIA_ANALYSIS_REQUESTED, $media->id, [
                'analysis' => $ticket->analysisId,
                'types' => $types,
                'unsupported_types' => $ticket->unsupportedTypes,
                'reanalyze' => $reanalyze,
            ]);
        }

        $analysis = MediaAnalysis::query()->with('reviews.reviewer')->findOrFail($ticket->analysisId);

        return $ticket->outcome === MediaAnalysisOutcome::QUEUED
            ? $this->successResponse(new MediaAnalysisResource($analysis), 'api.media_analysis.queued', 202)
            : $this->successResponse(new MediaAnalysisResource($analysis), 'api.media_analysis.reused');
    }

    /**
     * One analysis, with its reviews.
     */
    #[Response(200, type: 'array{success: bool, data: MediaAnalysisResource}')]
    #[Response(404, description: 'NOT_FOUND: no such analysis.')]
    public function show(string $analysis): JsonResponse
    {
        $row = MediaAnalysis::query()->with('reviews.reviewer')->find($analysis);

        return $row === null
            ? $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404)
            : $this->successResponse(new MediaAnalysisResource($row));
    }

    /**
     * Withdraw an analysis that has not started.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: MediaAnalysisResource}')]
    #[Response(404, description: 'NOT_FOUND: no such analysis.')]
    #[Response(409, description: 'MEDIA_ANALYSIS_NOT_CANCELLABLE: only a pending analysis can be withdrawn.')]
    public function cancel(string $analysis): JsonResponse
    {
        if (MediaAnalysis::query()->whereKey($analysis)->doesntExist()) {
            return $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404);
        }

        if (! $this->analyses->cancel($analysis)) {
            return $this->errorResponse('MEDIA_ANALYSIS_NOT_CANCELLABLE', 'api.error.media_analysis.not_cancellable', null, 409);
        }

        return $this->successResponse(
            new MediaAnalysisResource(MediaAnalysis::query()->with('reviews.reviewer')->findOrFail($analysis)),
            'api.media_analysis.cancelled',
        );
    }

    /**
     * Record a person's review of an assessment. The analysis itself is never changed. Recorded in the audit trail.
     */
    #[Response(201, type: 'array{success: bool, message: string, data: MediaAnalysisResource}')]
    #[Response(404, description: 'NOT_FOUND: no such analysis.')]
    #[Response(409, description: 'MEDIA_ANALYSIS_NOT_REVIEWABLE: only a completed or inconclusive analysis can be reviewed.')]
    public function review(ReviewMediaAnalysisRequest $request, string $analysis): JsonResponse
    {
        $row = MediaAnalysis::query()->find($analysis);

        if ($row === null) {
            return $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404);
        }

        if (! $row->status->isAssessment()) {
            return $this->errorResponse('MEDIA_ANALYSIS_NOT_REVIEWABLE', 'api.error.media_analysis.not_reviewable', null, 409);
        }

        $note = $request->validated('note');
        $decision = (string) $request->validated('decision');

        DB::transaction(function () use ($request, $row, $note, $decision): void {
            $reviewer = $request->user()?->getAuthIdentifier();

            MediaAnalysisReview::query()->create([
                'media_analysis_id' => $row->id,
                'reviewer_id' => is_string($reviewer) ? $reviewer : null,
                'decision' => $decision,
                'note' => is_string($note) && trim($note) !== '' ? $note : null,
            ]);

            // The decision and that a note exists; never the note, which is free text a
            // reviewer wrote for the analysis and has no business in a trail read by others.
            $this->audit->succeeded(AuditAction::MEDIA_ANALYSIS_REVIEWED, $row->id, [
                'media' => $row->media_file_id,
                'decision' => $decision,
                'status' => $row->status->value,
                'note_present' => is_string($note) && trim($note) !== '',
            ]);
        });

        return $this->successResponse(
            new MediaAnalysisResource($row->refresh()->load('reviews.reviewer')),
            'api.media_analysis.reviewed',
            201,
        );
    }
}
