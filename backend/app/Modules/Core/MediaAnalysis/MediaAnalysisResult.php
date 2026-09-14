<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

/**
 * One analysis, as any consumer reads it (ADR 0054).
 *
 * An assessment, never a verdict. There is no boolean anywhere in it saying the media is or
 * is not generated. What it carries instead:
 *
 * * a status, of which only `completed` and `inconclusive` are assessments;
 * * a score between 0 and 1 for each requested type the analyzer supported — a type it did
 *   not support is absent from `scores` and listed in `unsupportedTypes`, never scored 0;
 * * an optional confidence the analyzer reported;
 * * an advisory classification derived from the operator's thresholds, recorded with the
 *   policy version it was derived under, and null when no thresholds exist;
 * * which provider, analyzer and model produced it, the fingerprint of the input, and when.
 *
 * Results are append-only. A later analysis of the same media supersedes an earlier one by
 * pointing at it; the earlier one is never rewritten.
 *
 * What a consumer does with a result — review, label, hide, ignore — is the consumer's.
 */
final readonly class MediaAnalysisResult
{
    /**
     * @param  list<string>  $types  the types sent to the analyzer
     * @param  array<string, float>  $scores
     * @param  list<string>  $unsupportedTypes
     * @param  list<array<string, mixed>>  $signals
     */
    public function __construct(
        public string $id,
        public string $mediaId,
        public string $consumer,
        public MediaAnalysisStatus $status,
        public array $types,
        public ?MediaAnalysisClassification $classification,
        public ?float $confidence,
        public array $scores,
        public array $unsupportedTypes,
        public array $signals,
        public ?string $provider,
        public ?string $analyzer,
        public ?string $modelVersion,
        public string $policyVersion,
        public string $inputFingerprint,
        public int $attempts,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $requestedAt,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $supersededBy,
        public ?string $reanalysisOf,
    ) {}

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function score(MediaAnalysisType|string $type): ?float
    {
        return $this->scores[$type instanceof MediaAnalysisType ? $type->value : $type] ?? null;
    }

    /**
     * @return array{id: string, media_id: string, consumer: string, status: string, types: list<string>, classification: string|null, confidence: float|null, scores: array<string, float>, unsupported_types: list<string>, signals: list<array<string, mixed>>, provider: string|null, analyzer: string|null, model_version: string|null, policy_version: string, input_fingerprint: string, attempts: int, error_code: string|null, error_message: string|null, requested_at: string|null, started_at: string|null, completed_at: string|null, superseded_by: string|null, reanalysis_of: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'media_id' => $this->mediaId,
            'consumer' => $this->consumer,
            'status' => $this->status->value,
            'types' => $this->types,
            'classification' => $this->classification?->value,
            'confidence' => $this->confidence,
            'scores' => $this->scores,
            'unsupported_types' => $this->unsupportedTypes,
            'signals' => $this->signals,
            'provider' => $this->provider,
            'analyzer' => $this->analyzer,
            'model_version' => $this->modelVersion,
            'policy_version' => $this->policyVersion,
            'input_fingerprint' => $this->inputFingerprint,
            'attempts' => $this->attempts,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'requested_at' => $this->requestedAt,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'superseded_by' => $this->supersededBy,
            'reanalysis_of' => $this->reanalysisOf,
        ];
    }
}
