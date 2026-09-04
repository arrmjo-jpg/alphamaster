<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when an MFA enrolment or management action is not valid for the user's current state or for the policy that applies to them.
 *
 * The reason varies by call site, so the call site names the message; the class
 * names the failure.
 */
class MfaEnrolmentException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        private readonly string $translationKey,
        private readonly array $parameters = []
    ) {
        parent::__construct(self::englishMessage($translationKey, $parameters));
    }

    public function translationKey(): string
    {
        return $this->translationKey;
    }

    public function translationParameters(): array
    {
        return $this->parameters;
    }
}
