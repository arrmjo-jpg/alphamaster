<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

/**
 * One translatable field of one item: what it is, and what has been written for it so far
 * (ADR 0043, ADR 0056).
 *
 * The values are keyed by locale and are deliberately sparse: a locale absent from the map is
 * a locale nobody has translated this field into. An empty string is the same absence written
 * differently, and both are treated as untranslated.
 *
 * The rest describes the field, so the workshop never has to ask an operator which fields to
 * translate or how. A field is only ever declared here if it is text a person reads in one
 * language: identifiers, slugs, statuses, ordering, media references and anything technical
 * are not fields of a source at all.
 */
final class TranslationField
{
    /**
     * @param  string  $name  the attribute as its owning module names it
     * @param  string  $label  a translation key for what to call it on screen
     * @param  array<string, string>  $values  locale => the text written for it
     * @param  bool  $multiline  whether it holds a sentence or a paragraph
     * @param  bool  $required  whether the item is incomplete in a language without it
     * @param  FieldType  $type  how its text is translated
     * @param  FieldGroup  $group  what it is for
     * @param  int|null  $maxLength  the most characters the owner accepts, where it limits them
     * @param  bool  $translatable  false for a field shown for context but never sent to be translated
     * @param  array<string, scalar|null>  $meta  anything further a later consumer needs, without a contract change
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly array $values,
        public readonly bool $multiline = false,
        public readonly bool $required = true,
        public readonly FieldType $type = FieldType::PLAIN_TEXT,
        public readonly FieldGroup $group = FieldGroup::CONTENT,
        public readonly ?int $maxLength = null,
        public readonly bool $translatable = true,
        public readonly array $meta = [],
    ) {}

    /**
     * Whether this field has been translated into a locale.
     */
    public function hasValueFor(string $locale): bool
    {
        $value = $this->values[$locale] ?? '';

        return trim($value) !== '';
    }

    public function valueFor(string $locale): ?string
    {
        return $this->values[$locale] ?? null;
    }

    /**
     * The field as a client needs to render and translate it, without its values.
     *
     * @return array{name: string, label: string, multiline: bool, required: bool, type: string, group: string, max_length: int|null, translatable: bool}
     */
    public function describe(): array
    {
        return [
            'name' => $this->name,
            'label' => __($this->label),
            'multiline' => $this->multiline || $this->type === FieldType::HTML,
            'required' => $this->required,
            'type' => $this->type->value,
            'group' => $this->group->value,
            'max_length' => $this->maxLength,
            'translatable' => $this->translatable,
        ];
    }
}
