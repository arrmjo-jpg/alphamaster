<?php

declare(strict_types=1);

namespace App\Modules\Localization\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trait providing native relational translations architecture for Eloquent models.
 * Separates entity translations into distinct relational tables (_translations).
 */
trait HasTranslations
{
    /**
     * Get the class name of the translation model.
     */
    public function getTranslationModelName(): string
    {
        return property_exists($this, 'translationModel') && $this->translationModel
            ? $this->translationModel
            : static::class.'Translation';
    }

    /**
     * Relationship to the model translations.
     */
    public function translations(): HasMany
    {
        return $this->hasMany($this->getTranslationModelName(), $this->getTranslationForeignKey());
    }

    /**
     * Foreign key for translations relation.
     */
    public function getTranslationForeignKey(): string
    {
        return property_exists($this, 'translationForeignKey') && $this->translationForeignKey
            ? $this->translationForeignKey
            : $this->getForeignKey();
    }

    /**
     * Retrieve translated attribute with automatic locale fallback.
     */
    public function getTranslation(string $attribute, ?string $locale = null, bool $fallback = true): ?string
    {
        $targetLocale = $locale ?? app()->getLocale();

        // Check if relation is loaded to prevent N+1 queries
        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->get();

        $matched = $translations->firstWhere('locale', $targetLocale);

        if ($matched && isset($matched->{$attribute}) && $matched->{$attribute} !== '') {
            return (string) $matched->{$attribute};
        }

        if ($fallback) {
            $defaultLocale = (string) config('app.locale', 'en');
            if ($targetLocale !== $defaultLocale) {
                $fallbackMatch = $translations->firstWhere('locale', $defaultLocale);
                if ($fallbackMatch && isset($fallbackMatch->{$attribute})) {
                    return (string) $fallbackMatch->{$attribute};
                }
            }
        }

        return null;
    }

    /**
     * Translate model to specific locale instance.
     */
    public function translate(?string $locale = null, bool $fallback = true): ?Model
    {
        $targetLocale = $locale ?? app()->getLocale();

        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->get();

        $matched = $translations->firstWhere('locale', $targetLocale);

        if ($matched) {
            return $matched;
        }

        if ($fallback) {
            $defaultLocale = (string) config('app.locale', 'en');

            return $translations->firstWhere('locale', $defaultLocale);
        }

        return null;
    }
}
