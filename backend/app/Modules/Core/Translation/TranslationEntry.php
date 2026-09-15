<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

/**
 * One translatable item: a role, a notification template, a setting, a page (ADR 0043).
 *
 * The item is the unit of translation (ADR 0056). It is translated, reviewed and accepted as a
 * whole, and its status in a language is derived from its fields' metadata rather than from
 * counting every field alike.
 *
 * `id` is whatever its owning module uses to address it, and travels back unchanged on a
 * write — the workshop never parses it.
 */
final class TranslationEntry
{
    /**
     * @param  string  $id  the item's identifier within its own source
     * @param  string  $title  what to call it on screen, in the platform's own words
     * @param  array<int, TranslationField>  $fields
     * @param  string|null  $context  a sentence about what the item is for, where one helps
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $fields,
        public readonly ?string $context = null,
    ) {}

    /**
     * The fields that are sent to be translated.
     *
     * @return list<TranslationField>
     */
    public function translatableFields(): array
    {
        return array_values(array_filter($this->fields, static fn (TranslationField $field): bool => $field->translatable));
    }

    /**
     * How many of this item's required fields are still untranslated in a locale.
     */
    public function missingIn(string $locale): int
    {
        $missing = 0;

        foreach ($this->translatableFields() as $field) {
            if ($field->required && ! $field->hasValueFor($locale)) {
                $missing++;
            }
        }

        return $missing;
    }

    public function progressIn(string $locale): TranslationProgress
    {
        $values = [];
        $required = [];

        foreach ($this->translatableFields() as $field) {
            $values[$field->name] = $field->valueFor($locale);

            if ($field->required) {
                $required[] = $field->name;
            }
        }

        return TranslationProgress::of($values, $required);
    }

    /**
     * Where the item stands in a locale from what is written alone. A translation in progress
     * — pending, ready, failed — is known only to whoever runs it, and is laid over this.
     */
    public function statusIn(string $locale): TranslationItemStatus
    {
        $progress = $this->progressIn($locale);

        // Nothing written is "not translated" before anything else, so an item whose fields
        // are all optional is not counted as finished for having none of them.
        return match (true) {
            $progress->filled === 0 => TranslationItemStatus::NOT_TRANSLATED,
            $progress->complete => TranslationItemStatus::TRANSLATED,
            default => TranslationItemStatus::INCOMPLETE,
        };
    }
}
