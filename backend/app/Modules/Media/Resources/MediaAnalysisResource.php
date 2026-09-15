<?php

declare(strict_types=1);

namespace App\Modules\Media\Resources;

use App\Modules\Core\MediaAnalysis\MediaAnalysisClassification;
use App\Modules\Core\MediaAnalysis\MediaAnalysisStatus;
use App\Modules\Core\MediaAnalysis\MediaAnalysisType;
use App\Modules\Media\Models\MediaAnalysis;
use App\Modules\Media\Models\MediaAnalysisReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An analysis, as an operator reads it (ADR 0054).
 *
 * The result exactly as a consumer receives it, with labels beside the identifiers
 * (ADR 0030) and the reviews recorded against it. There is no field saying whether the media
 * is generated; there are scores, the types they belong to, and the versions that produced
 * them.
 *
 * Fields are listed one by one rather than spread from the result, so the published contract
 * states each type — a spread array reaches the generator as nothing it can read.
 *
 * @property-read MediaAnalysis $resource
 */
class MediaAnalysisResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = $this->resource->toResult();

        return [
            'id' => $result->id,
            'media_id' => $result->mediaId,
            'consumer' => $result->consumer,
            /** @var MediaAnalysisStatus */
            'status' => $result->status->value,
            'status_label' => $result->status->label(),
            /** @var list<MediaAnalysisType> */
            'types' => $result->types,
            /** @var MediaAnalysisClassification|null */
            'classification' => $result->classification?->value,
            'classification_label' => $result->classification?->label(),
            'confidence' => $result->confidence,
            /**
             * A score between 0 and 1 for each type the analyzer assessed. A type it did not assess is absent, never 0.
             *
             * @var array<string, float>
             */
            'scores' => $result->scores,
            /** @var list<MediaAnalysisType> */
            'unsupported_types' => $result->unsupportedTypes,
            /** @var list<array<string, mixed>> */
            'signals' => $result->signals,
            'provider' => $result->provider,
            'analyzer' => $result->analyzer,
            'model_version' => $result->modelVersion,
            'policy_version' => $result->policyVersion,
            'input_fingerprint' => $result->inputFingerprint,
            'attempts' => $result->attempts,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'requested_at' => $result->requestedAt,
            'started_at' => $result->startedAt,
            'completed_at' => $result->completedAt,
            'superseded_by' => $result->supersededBy,
            'reanalysis_of' => $result->reanalysisOf,
            /** @var array<string, string> */
            'type_labels' => collect([...$result->types, ...$result->unsupportedTypes])
                ->unique()
                ->mapWithKeys(static fn (string $type): array => [$type => MediaAnalysisType::from($type)->label()])
                ->all(),
            'reviews' => $this->resource->relationLoaded('reviews')
                ? $this->resource->reviews
                    ->sortByDesc('created_at')
                    ->values()
                    ->map(static fn (MediaAnalysisReview $review): array => [
                        'id' => $review->id,
                        'decision' => $review->decision->value,
                        'decision_label' => $review->decision->label(),
                        'note' => $review->note,
                        'reviewer' => $review->reviewer?->email,
                        'created_at' => $review->created_at?->toIso8601String(),
                    ])
                    ->all()
                : [],
        ];
    }
}
