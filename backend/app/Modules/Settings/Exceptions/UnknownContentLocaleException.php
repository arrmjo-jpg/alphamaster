<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a caller names a content language the platform does not know.
 *
 * The content language says which language a localized value is read or written in.
 * It is checked against every language the platform knows rather than the served ones,
 * because a draft is translatable before it is served (ADR 0048) — so this is raised
 * for a language that does not exist at all, not for one that is merely switched off.
 */
class UnknownContentLocaleException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct(public readonly string $locale)
    {
        parent::__construct(self::englishMessage($this->translationKey(), $this->translationParameters()));
    }

    public function translationKey(): string
    {
        return 'api.error.settings.unknown_locale';
    }

    public function translationParameters(): array
    {
        return ['locale' => $this->locale];
    }
}
