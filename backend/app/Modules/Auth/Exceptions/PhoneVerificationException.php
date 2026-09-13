<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a phone number cannot be confirmed, for a reason the caller may know.
 *
 * The reasons are deliberately few and deliberately vague where vagueness protects
 * something: a wrong code and an expired code answer the same way, because telling a
 * guesser which of the two they hit tells them whether to keep guessing.
 */
class PhoneVerificationException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    /**
     * @param  array<string, string|int>  $parameters
     */
    public function __construct(
        private readonly string $key,
        private readonly array $parameters = [],
        public readonly int $status = 422,
    ) {
        parent::__construct(self::englishMessage($this->key, $this->parameters));
    }

    public static function noNumber(): self
    {
        return new self('api.error.auth.phone_verification_no_number');
    }

    public static function alreadyVerified(): self
    {
        return new self('api.error.auth.phone_already_verified');
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(
            'api.error.auth.phone_verification_throttled',
            ['seconds' => $retryAfterSeconds],
            429
        );
    }

    /**
     * No live code, a wrong one, or one whose number has since changed. One answer for
     * all three: each is "this did not confirm anything", and distinguishing them
     * would say more to somebody guessing than to somebody who mistyped.
     */
    public static function invalidCode(): self
    {
        return new self('api.error.auth.phone_verification_invalid_code');
    }

    public function translationKey(): string
    {
        return $this->key;
    }

    public function translationParameters(): array
    {
        return $this->parameters;
    }
}
