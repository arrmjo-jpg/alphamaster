<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when an update names a setting key that does not exist in its group.
 */
class UnknownSettingKeyException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct(public readonly string $group, public readonly string $key)
    {
        parent::__construct(self::englishMessage($this->translationKey(), $this->translationParameters()));
    }

    public function translationKey(): string
    {
        return 'api.error.settings.unknown_key';
    }

    public function translationParameters(): array
    {
        return ['group' => $this->group, 'key' => $this->key];
    }
}
