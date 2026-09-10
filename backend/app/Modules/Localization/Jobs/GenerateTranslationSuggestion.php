<?php

declare(strict_types=1);

namespace App\Modules\Localization\Jobs;

use App\Modules\Core\Ai\TextGeneratorContract;
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\TranslationSuggestion;
use App\Modules\Localization\Services\TranslationPrompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ask the configured vendor to translate one field, and record what came back.
 *
 * Queued, never in a request path (ADR 0044 §4). A generation takes seconds by
 * construction, and a synchronous call would hold a PHP-FPM worker for the duration —
 * so an AI vendor having a slow minute would consume the pool that serves sign-in.
 * The consequence for the interface is that a suggestion arrives when it arrives, and
 * the workshop is built to show that rather than to wait.
 *
 * One job per field rather than one per batch. A translator works down a list, and a
 * failure should cost the entry it belongs to rather than the batch nobody knew they
 * had sent — the same reasoning the workshop's write endpoint follows.
 *
 * It takes an id rather than a model so a retry re-reads current state instead of
 * acting on a snapshot from before an earlier attempt.
 */
class GenerateTranslationSuggestion implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt, and no automatic retry.
     *
     * A retry is a second call the operator pays for, against a vendor that just
     * refused. Where the refusal is a bad model name or a missing credential — the two
     * most likely — every retry fails identically and bills for the privilege. The
     * operator retries by asking again, which is a decision rather than a loop.
     */
    public int $tries = 1;

    public function __construct(public readonly string $suggestionId)
    {
        // The queue that already exists for vendor calls (ADR 0020). AI belongs with
        // the other outbound work rather than on `default`, where a slow vendor would
        // sit in front of everything a person is waiting for.
        $this->onQueue('integrations');
    }

    public function handle(TextGeneratorContract $generator): void
    {
        $suggestion = TranslationSuggestion::query()->find($this->suggestionId);

        // Dismissed or accepted between dispatch and execution. Not a failure, and not
        // a reason to spend on a vendor call nobody is waiting for.
        if ($suggestion === null || $suggestion->status !== SuggestionStatus::PENDING) {
            return;
        }

        $from = $this->defaultLanguage();
        $into = Language::query()->where('code', $suggestion->locale)->first();

        if ($from === null || $into === null) {
            $this->fail($suggestion, 'UNKNOWN_LANGUAGE', 'The source or target language is no longer configured.');

            return;
        }

        $result = $generator->generate(TranslationPrompt::for(
            sourceText: $suggestion->source_text,
            from: $from,
            into: $into,
            fieldLabel: $suggestion->field,
            model: $this->model(),
            maxOutputTokens: $this->maxOutputTokens(),
        ));

        if (! $result->successful) {
            $this->fail(
                $suggestion,
                $result->errorCode ?? 'GENERATION_FAILED',
                $result->errorMessage ?? 'The provider did not answer.'
            );

            return;
        }

        $suggestion->forceFill([
            'status' => SuggestionStatus::READY,
            'suggestion' => $result->text,
            'error_code' => null,
            'error_message' => null,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * A job that dies for a reason nobody caught still has to leave the row readable.
     *
     * Without this a killed worker leaves a suggestion pending for ever, and the
     * workshop shows a spinner that never resolves — which reads as the platform being
     * broken rather than as one vendor call having failed.
     */
    public function failed(\Throwable $exception): void
    {
        $suggestion = TranslationSuggestion::query()->find($this->suggestionId);

        if ($suggestion === null || $suggestion->status !== SuggestionStatus::PENDING) {
            return;
        }

        $this->fail($suggestion, 'JOB_FAILED', $exception->getMessage());
    }

    private function fail(TranslationSuggestion $suggestion, string $code, string $message): void
    {
        $suggestion->forceFill([
            'status' => SuggestionStatus::FAILED,
            'error_code' => $code,
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();
    }

    private function defaultLanguage(): ?Language
    {
        /** @var Language|null $language */
        $language = Language::query()->where('is_default', true)->first();

        return $language;
    }

    private function model(): string
    {
        $configured = setting('ai.translation_model', 'gpt-4o-mini');

        return is_string($configured) && $configured !== '' ? $configured : 'gpt-4o-mini';
    }

    private function maxOutputTokens(): int
    {
        $configured = setting('ai.max_output_tokens', 512);

        return is_int($configured) && $configured > 0 ? $configured : 512;
    }
}
