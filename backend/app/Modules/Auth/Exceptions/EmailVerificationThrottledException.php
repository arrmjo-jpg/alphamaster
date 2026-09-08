<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a verification link was requested again too soon.
 *
 * The cooldown is not about load. Every request sends real mail to a real address,
 * so an endpoint without one lets anyone holding a session fill someone's inbox, and
 * a client that retries on a spinner does it by accident.
 */
class EmailVerificationThrottledException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(self::englishMessage($this->translationKey(), $this->translationParameters()));
    }

    public function translationKey(): string
    {
        return 'api.error.auth.email_verification_throttled';
    }

    public function translationParameters(): array
    {
        return ['seconds' => $this->retryAfterSeconds];
    }
}
