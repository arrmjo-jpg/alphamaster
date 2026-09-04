<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a non-administrator account is given admin roles or permissions.
 */
class NotAnAdminAccountException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct(public readonly string $userId)
    {
        parent::__construct(self::englishMessage($this->translationKey(), $this->translationParameters()));
    }

    public function translationKey(): string
    {
        return 'api.error.authorization.not_an_admin';
    }

    public function translationParameters(): array
    {
        return ['id' => $this->userId];
    }
}
