<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\Modules\Core\MediaAnalysis\AnalyzerDescriptor;
use App\Modules\Core\MediaAnalysis\AnalyzerOutcome;
use App\Modules\Core\MediaAnalysis\MediaAnalysisInput;
use App\Modules\Integration\Models\IntegrationProvider;

/**
 * One media analysis vendor or local analyzer, as the platform drives it (ADR 0017, ADR 0054).
 *
 * Everything vendor-specific stops here: the address, how a credential is presented, how the
 * file reaches the analyzer (a stream or a short-lived URL from the input), what the model is
 * called, which types and files it supports, and how its response maps onto scores. Adding a
 * vendor is a class implementing this and a `create<Name>Driver` method on the manager;
 * nothing that requests an analysis changes.
 */
interface MediaAnalysisProviderContract
{
    /**
     * The configuration fields this driver needs, by name. Settings are shown; credentials
     * are write-only.
     *
     * @return array{settings: list<string>, credentials: list<string>}
     */
    public function configurationFields(): array;

    /**
     * Required configuration the row lacks, by field name, never by value.
     *
     * @return list<string>
     */
    public function missingConfiguration(IntegrationProvider $provider): array;

    public function descriptor(IntegrationProvider $provider): AnalyzerDescriptor;

    /**
     * Analyse. A vendor refusal, timeout or rate limit is an outcome, not an exception.
     */
    public function analyze(MediaAnalysisInput $input, IntegrationProvider $provider): AnalyzerOutcome;
}
