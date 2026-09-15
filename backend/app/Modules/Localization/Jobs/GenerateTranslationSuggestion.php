<?php

declare(strict_types=1);

namespace App\Modules\Localization\Jobs;

use App\Modules\Core\Ai\ErrorRedactor;
use App\Modules\Core\Ai\TextGeneratorContract;
use App\Modules\Core\Translation\DeclaresSourceLocale;
use App\Modules\Core\Translation\FieldType;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\TranslationBatch;
use App\Modules\Localization\Models\TranslationSuggestion;
use App\Modules\Localization\Services\HtmlFidelity;
use App\Modules\Localization\Services\PlaceholderFidelity;
use App\Modules\Localization\Services\TranslationPrompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ask the configured vendor to translate one field of a batch, and record what came back.
 *
 * Queued, never in a request path (ADR 0044 §4). A generation takes seconds by construction,
 * and a synchronous call would hold a PHP-FPM worker for the duration — so an AI vendor having
 * a slow minute would consume the pool that serves sign-in.
 *
 * One job per field, inside one batch per item (ADR 0056). The operator sees the batch; the job
 * works on a field, so a page body that takes a minute does not hold its title back, and one
 * field failing is recorded against that field. Every job that finishes recounts its batch.
 *
 * What comes back is checked before anybody is shown it: text longer than the field accepts is
 * a failure rather than something to trim silently, and an HTML field whose tags or links did
 * not survive is a failure rather than something a reviewer has to notice.
 *
 * It takes an id rather than a model so a retry re-reads current state instead of acting on a
 * snapshot from before an earlier attempt.
 */
class GenerateTranslationSuggestion implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt, and no automatic retry.
     *
     * A retry is a second call the operator pays for, against a vendor that just refused. The
     * operator retries by asking for the item again, which is a decision rather than a loop.
     */
    public int $tries = 1;

    public function __construct(public readonly string $suggestionId)
    {
        // The queue that already exists for vendor calls (ADR 0020).
        $this->onQueue('integrations');
    }

    public function handle(TextGeneratorContract $generator): void
    {
        $suggestion = TranslationSuggestion::query()->find($this->suggestionId);

        // Dismissed or accepted between dispatch and execution. Not a failure, and not a
        // reason to spend on a vendor call nobody is waiting for.
        if ($suggestion === null || $suggestion->status !== SuggestionStatus::PENDING) {
            return;
        }

        try {
            $this->generate($generator, $suggestion);
        } finally {
            $this->refreshBatch($suggestion);
        }
    }

    /**
     * A job that dies for a reason nobody caught still has to leave the row readable.
     *
     * Without this a killed worker leaves a field pending for ever, and its item shows a spinner
     * that never resolves — which reads as the platform being broken rather than as one vendor
     * call having failed.
     */
    public function failed(\Throwable $exception): void
    {
        $suggestion = TranslationSuggestion::query()->find($this->suggestionId);

        if ($suggestion === null || $suggestion->status !== SuggestionStatus::PENDING) {
            return;
        }

        $this->fail($suggestion, 'JOB_FAILED', $exception->getMessage());
        $this->refreshBatch($suggestion);
    }

    private function generate(TextGeneratorContract $generator, TranslationSuggestion $suggestion): void
    {
        $from = $this->sourceLanguage($suggestion);
        $into = Language::query()->where('code', $suggestion->locale)->first();

        if ($from === null || $into === null) {
            $this->fail($suggestion, 'UNKNOWN_LANGUAGE', 'The source or target language is no longer configured.');

            return;
        }

        $result = $generator->generate(TranslationPrompt::for(
            sourceText: $suggestion->source_text,
            from: $from,
            into: $into,
            fieldLabel: $this->labelOf($suggestion, $from->code),
            type: $suggestion->field_type,
            group: $suggestion->field_group,
            maxLength: $suggestion->max_length,
            minimumOutputTokens: $this->minimumOutputTokens(),
        ));

        if (! $result->successful) {
            $this->fail(
                $suggestion,
                $result->errorCode ?? 'GENERATION_FAILED',
                $result->errorMessage ?? 'The provider did not answer.'
            );

            return;
        }

        $text = TranslationPrompt::clean($result->text);

        if ($text === '') {
            $this->fail($suggestion, 'EMPTY_TRANSLATION', 'The provider answered with no text.');

            return;
        }

        if ($suggestion->max_length !== null && mb_strlen($text) > $suggestion->max_length) {
            $this->fail($suggestion, 'TRANSLATION_TOO_LONG', sprintf(
                'The translation is %d characters and this field allows %d.',
                mb_strlen($text),
                $suggestion->max_length
            ));

            return;
        }

        if (! PlaceholderFidelity::preserved($suggestion->source_text, $text)) {
            $this->fail($suggestion, 'PLACEHOLDERS_CHANGED', 'The translation did not keep the placeholders and plural forms of the source.');

            return;
        }

        if ($suggestion->field_type === FieldType::HTML && ! HtmlFidelity::preserved($suggestion->source_text, $text)) {
            $this->fail($suggestion, 'HTML_STRUCTURE_CHANGED', 'The translation did not keep the tags, links and attributes of the source.');

            return;
        }

        $suggestion->forceFill([
            'status' => SuggestionStatus::READY,
            'suggestion' => $text,
            'error_code' => null,
            'error_message' => null,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * The reason is shown to translators in the workshop. A generator's failure arrives already
     * clean; an exception caught here does not, so both pass the same filter before they are
     * kept.
     */
    private function fail(TranslationSuggestion $suggestion, string $code, string $message): void
    {
        $suggestion->forceFill([
            'status' => SuggestionStatus::FAILED,
            'error_code' => ErrorRedactor::code($code),
            'error_message' => ErrorRedactor::message($message, fallback: 'The provider did not answer.'),
            'completed_at' => now(),
        ])->save();
    }

    /**
     * The language the field is translated from: the one its source is written in (ADR 0049),
     * or the platform's default. A source language Language Management does not list is still
     * named to the model by its code.
     */
    private function sourceLanguage(TranslationSuggestion $suggestion): ?Language
    {
        $source = app(TranslationRegistry::class)->find($suggestion->source_key);

        if (! $source instanceof DeclaresSourceLocale) {
            return Language::query()->where('is_default', true)->first();
        }

        $code = $source->sourceLocale();

        return Language::query()->where('code', $code)->first()
            ?? new Language(['code' => $code, 'name' => $code, 'native_name' => $code, 'direction' => 'ltr']);
    }

    private function refreshBatch(TranslationSuggestion $suggestion): void
    {
        if ($suggestion->batch_id === null) {
            return;
        }

        TranslationBatch::query()->find($suggestion->batch_id)?->refreshFromFields();
    }

    /**
     * What the field is called, in the language it is translated from, so a subject line is
     * not translated as a sentence.
     */
    private function labelOf(TranslationSuggestion $suggestion, string $locale): string
    {
        $key = $suggestion->field_label;

        if ($key === null || $key === '') {
            return $suggestion->field;
        }

        $label = __($key, [], $locale);

        return is_string($label) && $label !== $key ? $label : $suggestion->field;
    }

    /**
     * The operator's floor for any one field. The budget grows with the source above it.
     */
    private function minimumOutputTokens(): int
    {
        $configured = setting('ai.max_output_tokens', 512);

        return is_int($configured) && $configured > 0 ? $configured : 512;
    }
}
