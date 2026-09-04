<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a suspended or deactivated account attempts to authenticate.
 */
class AccountInactiveException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct()
    {
        parent::__construct(self::englishMessage($this->translationKey()));
    }

    public function translationKey(): string
    {
        return 'api.error.auth.account_inactive';
    }
}
