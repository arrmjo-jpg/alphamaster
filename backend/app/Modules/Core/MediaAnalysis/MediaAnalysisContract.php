<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

/**
 * Media analysis — AI video detection first — as any consumer calls it (ADR 0054).
 *
 * Declared in Core, for the reason `TextGeneratorContract` is: any module, including one
 * whose dependency rule forbids Media or Integration, can call it. Media implements it and
 * owns the lifecycle; Integration owns the vendors behind it; nothing that calls it knows
 * which vendor answers.
 *
 * **Nothing is analysed unless a consumer asks.** Uploading media never starts an analysis,
 * no middleware or listener requests one, and an operator switching the capability on only
 * makes it available. Each consumer decides where, when, for which media and which types —
 * and what to do with the result.
 *
 * Never throws for the platform's state. A switched-off capability, a missing analyzer, an
 * unsupported file or a limit all come back as an outcome, so a consumer cannot forget to
 * handle them by forgetting to catch.
 */
interface MediaAnalysisContract
{
    /**
     * Whether the capability is switched on and configured, and which types it supports.
     */
    public function availability(): MediaAnalysisAvailability;

    /**
     * Ask for an analysis. Returns at once; the analysis runs on its own queue.
     *
     * An equivalent analysis of the same file (same content, types, analyzer, model and
     * policy) is reused rather than repeated, unless the request asks to re-analyse.
     */
    public function request(MediaAnalysisRequest $request): MediaAnalysisTicket;

    public function find(string $analysisId): ?MediaAnalysisResult;

    /**
     * The newest analysis of a media file that nothing has superseded, optionally only the
     * ones a given consumer requested.
     */
    public function latest(string $mediaId, ?string $consumer = null): ?MediaAnalysisResult;

    /**
     * Every analysis of a media file, newest first.
     *
     * @return list<MediaAnalysisResult>
     */
    public function history(string $mediaId): array;

    /**
     * Withdraw an analysis that has not started. Answers whether it was withdrawn.
     */
    public function cancel(string $analysisId): bool;
}
