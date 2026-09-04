<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when an email or password does not match.
 *
 * One message covers both causes deliberately: telling a caller which half was
 * wrong tells them which accounts exist.
 */
class InvalidCredentialsException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct()
    {
        parent::__construct(self::englishMessage($this->translationKey()));
    }

    public function translationKey(): string
    {
        return 'api.error.auth.invalid_credentials';
    }
}
