<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

/**
 * The analyzer behind media analysis, as Media drives it (ADR 0054).
 *
 * A second seam, and the reason there are two: Media may not depend on Integration, so the
 * vendor side is declared here too and Integration binds it, the direction
 * `TextGeneratorContract` already runs in. With no Integration binding, Core's null
 * analyzer answers that nothing is configured.
 *
 * Consumers never call this. They call `MediaAnalysisContract`.
 */
interface MediaAnalyzerContract
{
    /**
     * Whether an analysis would reach an analyzer at all: an active provider that holds
     * everything it needs. Says nothing about the vendor's health.
     */
    public function isConfigured(): bool;

    /**
     * What the configured analyzer is and what it accepts, or null when nothing is configured.
     */
    public function descriptor(): ?AnalyzerDescriptor;

    /**
     * Analyse. Never throws for a vendor refusal or timeout: those are outcomes.
     */
    public function analyze(MediaAnalysisInput $input): AnalyzerOutcome;
}
