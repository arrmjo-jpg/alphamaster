<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

/**
 * What an analyzer answered to one analysis (ADR 0054).
 *
 * `scores` holds only types the analyzer actually scored; a type it could not assess goes
 * in `unsupportedTypes` and is never given a number. `retryable` is the driver's judgement
 * of whether the same call could succeed later — a rate limit, an outage — as against one
 * that cannot.
 */
final readonly class AnalyzerOutcome
{
    /**
     * @param  array<string, float>  $scores
     * @param  list<string>  $unsupportedTypes
     * @param  list<array<string, mixed>>  $signals
     */
    private function __construct(
        public bool $successful,
        public bool $inconclusive = false,
        public array $scores = [],
        public array $unsupportedTypes = [],
        public ?float $confidence = null,
        public array $signals = [],
        public ?string $modelVersion = null,
        public ?string $reference = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $retryable = false,
        public ?int $retryAfterSeconds = null,
    ) {}

    /**
     * @param  array<string, float>  $scores
     * @param  list<string>  $unsupportedTypes
     * @param  list<array<string, mixed>>  $signals
     */
    public static function assessed(
        array $scores,
        array $unsupportedTypes = [],
        ?float $confidence = null,
        array $signals = [],
        ?string $modelVersion = null,
        ?string $reference = null,
    ): self {
        return new self(
            successful: true,
            scores: $scores,
            unsupportedTypes: $unsupportedTypes,
            confidence: $confidence,
            signals: $signals,
            modelVersion: $modelVersion,
            reference: $reference,
        );
    }

    /**
     * The analyzer ran and could not reach an assessment.
     *
     * @param  list<string>  $unsupportedTypes
     * @param  list<array<string, mixed>>  $signals
     * @param  array<string, float>  $scores  partial scores, if any
     */
    public static function inconclusive(
        array $unsupportedTypes = [],
        array $signals = [],
        ?string $modelVersion = null,
        ?string $reference = null,
        array $scores = [],
        ?float $confidence = null,
    ): self {
        return new self(
            successful: true,
            inconclusive: true,
            scores: $scores,
            unsupportedTypes: $unsupportedTypes,
            confidence: $confidence,
            signals: $signals,
            modelVersion: $modelVersion,
            reference: $reference,
        );
    }

    public static function failure(string $code, string $message, bool $retryable, ?int $retryAfterSeconds = null): self
    {
        return new self(
            successful: false,
            errorCode: $code,
            errorMessage: mb_substr($message, 0, 500),
            retryable: $retryable,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }
}
