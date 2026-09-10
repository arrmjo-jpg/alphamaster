<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Localization\Models\Language;

/**
 * What the platform asks a model when it wants a translation.
 *
 * This is code, deliberately and permanently (ADR 0044 §6). A prompt is not
 * configuration; it is the logic of the task. Making it editable from the Admin would
 * put the platform's behaviour outside its own version control, make a bad suggestion
 * irreproducible from the repository, and hand whoever holds `settings.update` the
 * ability to change what the platform tells a vendor about its own content.
 *
 * The instruction is written to be boring on purpose. A model asked to translate a
 * user-interface label will otherwise explain itself, offer alternatives, or wrap the
 * answer in quotation marks — and every one of those ends up pasted into a button.
 *
 * Placeholders are the one thing worth being emphatic about. Notification bodies carry
 * `:name` and `:event`; a translation that localises them produces a message with a
 * literal colon-word in it where a person's name should be.
 */
final class TranslationPrompt
{
    private const INSTRUCTION = <<<'PROMPT'
        You are translating short interface text for a software platform.

        Rules:
        - Reply with the translation only. No preamble, no explanation, no alternatives.
        - Do not wrap the answer in quotation marks unless the source is quoted.
        - Preserve every placeholder exactly as written, including its leading colon —
          :name, :event, :subject and any other. Never translate a placeholder.
        - Preserve the source's punctuation, capitalisation style and line breaks.
        - Match the register of the source: a button label stays a button label.
        - If the source is a single word, answer with a single word.
        PROMPT;

    /**
     * Build the request for one field.
     *
     * The source and target languages are named rather than passed as bare codes: a
     * model given "ar" guesses, and a model given "Arabic (العربية), right-to-left"
     * does not have to.
     */
    public static function for(
        string $sourceText,
        Language $from,
        Language $into,
        string $fieldLabel,
        string $model,
        int $maxOutputTokens,
    ): TextGenerationRequest {
        return new TextGenerationRequest(
            instruction: self::INSTRUCTION,
            content: $sourceText,
            model: $model,
            maxOutputTokens: $maxOutputTokens,
            // Near-deterministic. A translation of a label has a right answer, and
            // creative variation in a button is a defect. Fixed per task rather than
            // configured, for the same reason the prompt is (ADR 0044 §6).
            temperature: 0.1,
            context: [
                'Source language' => self::describe($from),
                'Target language' => self::describe($into),
                // What kind of text this is, so a subject line is not translated as a
                // sentence and a role label is not translated as an instruction.
                'Field' => $fieldLabel,
            ],
        );
    }

    private static function describe(Language $language): string
    {
        return sprintf(
            '%s (%s), code %s, %s',
            $language->name,
            $language->native_name,
            $language->code,
            $language->direction->value === 'rtl' ? 'right-to-left' : 'left-to-right'
        );
    }
}
