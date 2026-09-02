<?php

declare(strict_types=1);

namespace App\Modules\Localization\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;

interface TranslatableInterface
{
    /**
     * Define the relationship to entity translations.
     */
    public function translations(): HasMany;

    /**
     * Retrieve translated value for an attribute in the specified or current locale.
     */
    public function getTranslation(string $attribute, ?string $locale = null, bool $fallback = true): ?string;
}
