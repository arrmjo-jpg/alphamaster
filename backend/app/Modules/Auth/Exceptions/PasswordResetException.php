<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a password reset cannot be completed.
 *
 * One message for every cause — no such account, a token that is wrong, used or expired
 * — because telling them apart would tell a caller which addresses hold accounts.
 */
class PasswordResetException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct()
    {
        parent::__construct(self::englishMessage($this->translationKey()));
    }

    public function translationKey(): string
    {
        return 'api.error.auth.password_reset_invalid';
    }
}
