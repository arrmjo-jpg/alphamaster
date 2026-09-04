<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a settings group is requested that the platform does not define.
 */
class SettingGroupNotFoundException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct(public readonly string $group)
    {
        parent::__construct(self::englishMessage($this->translationKey(), $this->translationParameters()));
    }

    public function translationKey(): string
    {
        return 'api.error.settings.group_not_found';
    }

    public function translationParameters(): array
    {
        return ['group' => $this->group];
    }
}
