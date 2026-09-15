<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Translation\FieldGroup;
use App\Modules\Core\Translation\FieldType;
use App\Modules\Localization\Models\Language;

/**
 * What the platform asks a model when it wants a translation.
 *
 * This is code, deliberately and permanently (ADR 0044 §6). A prompt is not configuration; it
 * is the logic of the task. Making it editable from the Admin would put the platform's
 * behaviour outside its own version control, make a bad suggestion irreproducible from the
 * repository, and hand whoever holds `settings.update` the ability to change what the platform
 * tells a vendor about its own content.
 *
 * One instruction per kind of text, chosen from the field's metadata rather than from its name
 * (ADR 0056). A role label, a page body in HTML and a search result description are different
 * tasks: the first must stay a label, the second must come back with every tag and link where
 * it was, and the third must fit the length a search engine shows.
 *
 * Placeholders are the one thing worth being emphatic about in all three. Notification bodies
 * carry `:name` and `:event`; a translation that localises them produces a message with a
 * literal colon-word in it where a person's name should be.
 */
final class TranslationPrompt
{
    public const MODE_PLAIN_TEXT = 'plain_text';

    public const MODE_HTML = 'html';

    public const MODE_SEO = 'seo';

    /** The most any one field may ask a vendor for, whatever its length. */
    public const OUTPUT_CEILING = 16000;

    private const RULES = <<<'PROMPT'
        Rules that always apply:
        - Reply with the translation only. No preamble, no explanation, no alternatives.
        - Do not wrap the answer in quotation marks or code fences.
        - Preserve every placeholder exactly as written — :name, :event, {{count}}, {{name}},
          $t(key) and any other — and keep plural forms separated by | with their
          {0} or [2,*] markers. Never translate a placeholder.
        - Never translate URLs, email addresses, or code.
        PROMPT;

    private const PLAIN_TEXT = <<<'PROMPT'
        You are translating text for a software platform.

        - Preserve the source's punctuation, capitalisation style and line breaks.
        - Match the register of the source: a button label stays a button label, a paragraph
          stays a paragraph.
        - If the source is a single word, answer with a single word.
        PROMPT;

    private const HTML = <<<'PROMPT'
        You are translating an HTML fragment for a software platform.

        - Translate only the human-readable text between tags, and the values of the alt and
          title attributes.
        - Keep every tag exactly as it is: the same elements, the same nesting, and every
          other attribute — href, src, class, id, target, rel, data-* — character for
          character.
        - Do not add, remove, reorder or reformat tags, and do not add a document wrapper.
        - The answer is the translated HTML fragment and nothing else.
        PROMPT;

    private const SEO = <<<'PROMPT'
        You are translating search engine metadata — the title or description a search result
        shows — for a software platform.

        - Keep the meaning and the most important terms of the source, written naturally for a
          reader in the target language.
        - Stay within the maximum length given below, counted in characters. Shorten rather
          than exceed it.
        - One line. No quotation marks, no emoji, no keyword lists.
        PROMPT;

    /**
     * Build the request for one field.
     *
     * The source and target languages are named rather than passed as bare codes: a model given
     * a two-letter code guesses, and a model given the language's name, native name and writing
     * direction does not have to.
     */
    public static function for(
        string $sourceText,
        Language $from,
        Language $into,
        string $fieldLabel,
        FieldType $type,
        FieldGroup $group,
        ?int $maxLength,
        int $minimumOutputTokens,
    ): TextGenerationRequest {
        $mode = self::mode($type, $group);

        $context = [
            'Source language' => self::describe($from),
            'Target language' => self::describe($into),
            // What kind of text this is, so a subject line is not translated as a sentence
            // and a role label is not translated as an instruction.
            'Field' => $fieldLabel,
        ];

        if ($maxLength !== null) {
            $context['Maximum length'] = $maxLength.' characters';
        }

        return new TextGenerationRequest(
            instruction: self::instruction($mode),
            content: $sourceText,
            // No model: the provider that answers supplies its own, so a translation is never
            // sent to one vendor with another vendor's model name.
            maxOutputTokens: self::outputBudget($sourceText, $type, $minimumOutputTokens),
            // Near-deterministic. A translation has a right answer, and creative variation in a
            // button is a defect. Fixed per task rather than configured (ADR 0044 §6).
            temperature: 0.1,
            context: $context,
        );
    }

    public static function mode(FieldType $type, FieldGroup $group): string
    {
        return match (true) {
            $type === FieldType::HTML => self::MODE_HTML,
            $group === FieldGroup::SEO => self::MODE_SEO,
            default => self::MODE_PLAIN_TEXT,
        };
    }

    /**
     * How many tokens a field may come back in.
     *
     * Scaled to the source rather than one number for everything: a label needs a handful and a
     * page body can need thousands, and a fixed ceiling either truncates the body or invites a
     * label to ramble. The operator's `ai.max_output_tokens` is the floor, never lowered, and the
     * ceiling bounds what a single field can cost.
     */
    public static function outputBudget(string $sourceText, FieldType $type, int $minimum): int
    {
        // Generous on purpose: a translation into a script the tokenizer handles less well
        // takes more tokens per character than its source, and markup takes more still.
        $perCharacter = $type === FieldType::HTML ? 2.0 : 1.5;
        $estimate = (int) ceil(mb_strlen($sourceText) * $perCharacter) + 64;

        return min(self::OUTPUT_CEILING, max($minimum, $estimate));
    }

    /**
     * The answer as it is stored: trimmed, and out of the code fence a model sometimes puts it
     * in despite being told not to.
     */
    public static function clean(string $text): string
    {
        $trimmed = trim($text);

        if (preg_match('/^```[a-zA-Z]*\s*\R(.*)\R```$/s', $trimmed, $match) === 1) {
            return trim($match[1]);
        }

        return $trimmed;
    }

    private static function instruction(string $mode): string
    {
        $task = match ($mode) {
            self::MODE_HTML => self::HTML,
            self::MODE_SEO => self::SEO,
            default => self::PLAIN_TEXT,
        };

        return $task."\n\n".self::RULES;
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
