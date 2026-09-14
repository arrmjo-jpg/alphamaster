<?php

declare(strict_types=1);

namespace App\Modules\Media\Services\Analysis;

use App\Modules\Core\MediaAnalysis\MediaAnalysisClassification;

/**
 * The operator's configuration of media analysis, read from the `media_analysis` settings
 * group (ADR 0054).
 *
 * Availability and limits only. Nothing here says where analysis is used: that is each
 * consumer's decision, and no setting in this group can apply analysis to media nobody
 * asked about.
 *
 * Every limit is optional. An unset limit means the platform adds none of its own beyond
 * the analyzer's; the stricter of the two always applies. The classification thresholds are
 * optional too, and without them no classification is derived at all.
 */
class MediaAnalysisPolicy
{
    private const DEFAULT_TIMEOUT_SECONDS = 300;

    public function enabled(): bool
    {
        return setting('media_analysis.enabled', false) === true;
    }

    public function maxBytes(): ?int
    {
        $megabytes = $this->positiveInt('media_analysis.max_file_size_mb');

        return $megabytes === null ? null : $megabytes * 1024 * 1024;
    }

    public function maxDurationSeconds(): ?int
    {
        return $this->positiveInt('media_analysis.max_duration_seconds');
    }

    public function dailyLimit(): ?int
    {
        return $this->positiveInt('media_analysis.daily_limit');
    }

    public function timeoutSeconds(): int
    {
        return $this->positiveInt('media_analysis.timeout_seconds') ?? self::DEFAULT_TIMEOUT_SECONDS;
    }

    public function syntheticThreshold(): ?float
    {
        return $this->fraction('media_analysis.likely_synthetic_threshold');
    }

    public function authenticThreshold(): ?float
    {
        return $this->fraction('media_analysis.likely_authentic_threshold');
    }

    /**
     * Identifies the thresholds a classification was derived under, so a result can say
     * which policy read it and a change of thresholds is visible in every later result.
     */
    public function version(): string
    {
        $synthetic = $this->syntheticThreshold();
        $authentic = $this->authenticThreshold();

        if ($synthetic === null && $authentic === null) {
            return 'thresholds:none';
        }

        return 'thresholds:'.substr(hash('sha256', (string) json_encode([$synthetic, $authentic])), 0, 12);
    }

    /**
     * An advisory classification of scores, or null when no thresholds are configured or
     * there is nothing to classify.
     *
     * The highest score decides: media is as suspect as its most suspect indicator.
     *
     * @param  array<string, float>  $scores
     */
    public function classify(array $scores): ?MediaAnalysisClassification
    {
        $synthetic = $this->syntheticThreshold();
        $authentic = $this->authenticThreshold();

        if ($scores === [] || ($synthetic === null && $authentic === null)) {
            return null;
        }

        $highest = max($scores);

        return match (true) {
            $synthetic !== null && $highest >= $synthetic => MediaAnalysisClassification::LIKELY_SYNTHETIC,
            $authentic !== null && $highest <= $authentic => MediaAnalysisClassification::LIKELY_AUTHENTIC,
            default => MediaAnalysisClassification::INCONCLUSIVE,
        };
    }

    private function positiveInt(string $reference): ?int
    {
        $value = setting($reference);

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function fraction(string $reference): ?float
    {
        $value = setting($reference);

        return (is_float($value) || is_int($value)) && $value >= 0 && $value <= 1 ? (float) $value : null;
    }
}
