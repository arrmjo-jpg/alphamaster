<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

/**
 * One translatable field of one item, with whatever has been written for it so far.
 *
 * The values are keyed by locale and are deliberately sparse: a locale absent from
 * the map is a locale nobody has translated this field into, which is the fact the
 * whole workshop exists to surface. An empty string is the same absence written
 * differently, and both are treated as untranslated rather than as a translation
 * that happens to say nothing.
 */
final class TranslationField
{
    /**
     * @param  string  $name  the attribute as its owning module names it
     * @param  string  $label  a translation key for what to call it on screen
     * @param  array<string, string>  $values  locale => the text written for it
     * @param  bool  $multiline  whether it holds a sentence or a paragraph
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly array $values,
        public readonly bool $multiline = false,
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
}
