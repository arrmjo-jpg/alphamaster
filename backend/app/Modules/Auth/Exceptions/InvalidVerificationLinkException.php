<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a verification link's signature held but its contents no longer do.
 *
 * The signature proves the link came from here and has not expired. It cannot prove
 * that the account still exists, or that the address is still the one the link was
 * issued for — a link signed before an address changed stays validly signed, and
 * honouring it would mark the *new* address verified on the strength of a mail sent
 * to the old one.
 *
 * One message for every such case, so a caller cannot use the endpoint to learn
 * which accounts exist.
 */
class InvalidVerificationLinkException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct()
    {
        parent::__construct(self::englishMessage($this->translationKey(), []));
    }

    public function translationKey(): string
    {
        return 'api.error.auth.invalid_verification_link';
    }

    public function translationParameters(): array
    {
        return [];
    }
}
