<?php

declare(strict_types=1);

namespace App\Modules\Core\Concerns;

use App\Modules\Core\Contracts\LocaleResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Relational translations for a domain entity (ADR 0015).
 *
 * Each translatable entity owns a normalised `{entity}_translations` table keyed
 * UNIQUE(foreign_id, locale), rather than a JSON blob: a translation is a row that
 * can be queried, indexed, and administered like any other data.
 *
 * The trait lives in Core and resolves the active locale through
 * LocaleResolverInterface, so a module becomes translatable without depending on the
 * Localization module — the same inversion ADR 0015 established for SetLocale.
 *
 * An implementing model declares its translation model and the attributes that are
 * translated.
 */
trait HasTranslations
{
    /**
     * The model holding this entity's translations.
     *
     * @return class-string<Model>
     */
    abstract public function translationModel(): string;

    /**
     * The attributes stored per locale.
     *
     * @return array<int, string>
     */
    abstract public function translatableAttributes(): array;

    /**
     * The foreign key on the translation table.
     */
    public function translationForeignKey(): string
    {
        return $this->getForeignKey();
    }

    public function translations(): HasMany
    {
        return $this->hasMany($this->translationModel(), $this->translationForeignKey());
    }

    /**
     * Read one translated attribute.
     *
     * Falls back from the requested locale to the platform default, then to any
     * translation that exists, so a partially translated entity degrades to something
     * readable rather than to nothing. Returns null only when the entity has no
     * translation at all.
     */
    public function translate(string $attribute, ?string $locale = null): ?string
    {
        $this->assertTranslatable($attribute);

        $locale ??= app()->getLocale();
        $translations = $this->loadedTranslations();

        foreach ($this->localeFallbackChain($locale) as $candidate) {
            $row = $translations->firstWhere('locale', $candidate);
            $value = $row?->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $any = $translations->first(
            fn (Model $row): bool => is_string($row->getAttribute($attribute)) && $row->getAttribute($attribute) !== ''
        );

        return $any?->getAttribute($attribute);
    }

    /**
     * Create or replace the translation for one locale.
     *
     * @param  array<string, string>  $values
     */
    public function setTranslation(string $locale, array $values): Model
    {
        foreach (array_keys($values) as $attribute) {
            $this->assertTranslatable($attribute);
        }

        /** @var Model $translation */
        $translation = $this->translations()->updateOrCreate(['locale' => $locale], $values);

        $this->unsetRelation('translations');

        return $translation;
    }

    /**
     * The locales this entity has been translated into.
     *
     * @return array<int, string>
     */
    public function translatedLocales(): array
    {
        return $this->loadedTranslations()->pluck('locale')->all();
    }

    /**
     * Requested locale, then the platform default, without repeating either.
     *
     * @return array<int, string>
     */
    protected function localeFallbackChain(string $locale): array
    {
        $default = app(LocaleResolverInterface::class)->getDefaultLocale();

        return array_values(array_unique([$locale, $default]));
    }

    /**
     * Translations as a collection, loading them once.
     *
     * @return Collection<int, Model>
     */
    protected function loadedTranslations(): Collection
    {
        $this->loadMissing('translations');

        return $this->getRelation('translations');
    }

    /**
     * Guard against reading or writing an attribute the entity does not translate,
     * which would otherwise fail silently as a null.
     */
    protected function assertTranslatable(string $attribute): void
    {
        if (! in_array($attribute, $this->translatableAttributes(), true)) {
            throw new \InvalidArgumentException(
                sprintf('[%s] is not a translatable attribute of [%s].', $attribute, static::class)
            );
        }
    }
}
