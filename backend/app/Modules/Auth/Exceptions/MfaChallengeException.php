<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a multi-factor challenge is missing, expired, or answered wrongly.
 *
 * The reason varies by call site, so the call site names the message; the class
 * names the failure.
 */
class MfaChallengeException extends RuntimeException implements LocalizableException
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
