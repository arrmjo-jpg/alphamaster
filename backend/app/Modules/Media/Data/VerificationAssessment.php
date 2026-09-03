<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

use App\Modules\Media\Enums\VerificationStatus;

/**
 * What an analyzer concluded about a file's authenticity.
 *
 * Every signal is a risk score between 0 and 1, never a boolean. An analyzer can say
 * a file looks unusual; it cannot say a file is machine generated, and a type that
 * offered `aiGenerated: bool` would invite exactly the claim the platform must not
 * make. Consumers decide what a score means by applying their own threshold.
 *
 * Carries the analyzer and version so an assessment can be attributed and later
 * superseded by a better one rather than silently replaced.
 */
final readonly class VerificationAssessment
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public VerificationStatus $status,
        public string $analyzer,
        public string $version,
        public ?float $aiRiskScore = null,
        public ?float $deepfakeRiskScore = null,
        public ?float $manipulationRiskScore = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'analyzer' => $this->analyzer,
            'version' => $this->version,
            'ai_risk_score' => $this->aiRiskScore,
            'deepfake_risk_score' => $this->deepfakeRiskScore,
            'manipulation_risk_score' => $this->manipulationRiskScore,
            'metadata' => $this->metadata,
        ];
    }
}
