<?php

declare(strict_types=1);

namespace App\Modules\Core\Ai;

/**
 * The platform's ability to generate text, declared where every module can name it.
 *
 * In Core for the reason `EffectiveGrants` and `RetentionPolicyContract` are: the
 * answer lives in the Integration module, and the first consumer — the translation
 * workshop — is Localization, whose dependency rule names Core and the framework and
 * nothing else. Core declares what it needs to know; Integration binds it.
 *
 * There is no failover behind this (ADR 0044 §3). One provider answers, and a failure
 * is a failure. Failover exists because a transport is interchangeable — a recipient
 * cannot tell which carrier delivered a message — and a generator is not: falling to
 * a second vendor silently changes what the platform produced.
 */
interface TextGeneratorContract
{
    /**
     * Ask the configured provider for text.
     *
     * Never throws for a vendor refusal, a timeout or an unreadable credential: each
     * is an outcome the caller has to show somebody, and a task that has to catch to
     * find out is a task that will forget to.
     */
    public function generate(TextGenerationRequest $request): TextGenerationResult;

    /**
     * Whether a request would reach a vendor at all.
     *
     * Read by consumers that offer AI as an option, so a screen can leave the button
     * out rather than offer one that always fails. It answers about *configuration* —
     * an active provider that holds credentials — and never about the vendor's health,
     * which is only knowable by asking.
     */
    public function isConfigured(): bool;
}
