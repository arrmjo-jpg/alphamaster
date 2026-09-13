<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

/**
 * One translatable item: a role, a notification template, a setting.
 *
 * `id` is whatever its owning module uses to address it, and travels back unchanged
 * on a write — the workshop never parses it, so a source is free to key by ulid,
 * integer or `group.key` without this having to know which.
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
     * How many of this item's fields are still untranslated in a locale.
     */
    public function missingIn(string $locale): int
    {
        $missing = 0;

        foreach ($this->fields as $field) {
            if (! $field->hasValueFor($locale)) {
                $missing++;
            }
        }

        return $missing;
    }
}
