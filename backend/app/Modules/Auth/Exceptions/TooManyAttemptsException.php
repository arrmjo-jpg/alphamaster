<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a throttle rejects an attempt, carrying how long remains.
 */
class TooManyAttemptsException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(self::englishMessage($this->translationKey(), $this->translationParameters()));
    }

    public function translationKey(): string
    {
        return 'api.error.auth.too_many_attempts';
    }

    public function translationParameters(): array
    {
        return ['seconds' => $this->retryAfterSeconds];
    }
}
