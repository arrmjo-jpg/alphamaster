<?php

declare(strict_types=1);

namespace App\Modules\Media\Models;

use App\Modules\Core\MediaAnalysis\MediaAnalysisClassification;
use App\Modules\Core\MediaAnalysis\MediaAnalysisResult;
use App\Modules\Core\MediaAnalysis\MediaAnalysisStatus;
use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One analysis of a stored media file, and its outcome (ADR 0054).
 *
 * @property string $id
 * @property string $media_file_id
 * @property string $consumer
 * @property list<string> $types
 * @property MediaAnalysisStatus $status
 * @property MediaAnalysisClassification|null $classification
 * @property float|null $confidence
 * @property array<string, float>|null $scores
 * @property list<string>|null $unsupported_types
 * @property list<array<string, mixed>>|null $signals
 * @property string|null $provider
 * @property string|null $analyzer
 * @property string|null $model_version
 * @property string $policy_version
 * @property string $input_fingerprint
 * @property int $attempts
 * @property Carbon|null $available_at
 * @property string|null $reason
 * @property string|null $requested_by
 * @property string|null $reanalysis_of
 * @property string|null $superseded_by
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $provider_reference
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaAnalysis extends BaseModel
{
    protected $table = 'media_analyses';

    protected $fillable = [
        'media_file_id',
        'consumer',
        'types',
        'status',
        'classification',
        'confidence',
        'scores',
        'unsupported_types',
        'signals',
        'provider',
        'analyzer',
        'model_version',
        'policy_version',
        'input_fingerprint',
        'attempts',
        'available_at',
        'reason',
        'requested_by',
        'reanalysis_of',
        'superseded_by',
        'error_code',
        'error_message',
        'provider_reference',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'types' => 'array',
            'status' => MediaAnalysisStatus::class,
            'classification' => MediaAnalysisClassification::class,
            'confidence' => 'float',
            'scores' => 'array',
            'unsupported_types' => 'array',
            'signals' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ]);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id');
    }

    /**
     * @return HasMany<MediaAnalysisReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(MediaAnalysisReview::class, 'media_analysis_id');
    }

    public function toResult(): MediaAnalysisResult
    {
        $scores = [];

        foreach ($this->scores ?? [] as $type => $score) {
            $scores[(string) $type] = (float) $score;
        }

        return new MediaAnalysisResult(
            id: $this->id,
            mediaId: $this->media_file_id,
            consumer: $this->consumer,
            status: $this->status,
            types: $this->types,
            classification: $this->classification,
            confidence: $this->confidence,
            scores: $scores,
            unsupportedTypes: $this->unsupported_types ?? [],
            signals: $this->signals ?? [],
            provider: $this->provider,
            analyzer: $this->analyzer,
            modelVersion: $this->model_version,
            policyVersion: $this->policy_version,
            inputFingerprint: $this->input_fingerprint,
            attempts: $this->attempts,
            errorCode: $this->error_code,
            errorMessage: $this->error_message,
            requestedAt: $this->created_at?->toIso8601String(),
            startedAt: $this->started_at?->toIso8601String(),
            completedAt: $this->completed_at?->toIso8601String(),
            supersededBy: $this->superseded_by,
            reanalysisOf: $this->reanalysis_of,
        );
    }
}
