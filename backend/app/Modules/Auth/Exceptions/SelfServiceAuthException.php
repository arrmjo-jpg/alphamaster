<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * A refusal from phone sign-in or public registration (ADR 0051), for a reason the
 * caller may know.
 *
 * Vague where vagueness protects something: a wrong code, an expired one and a code for
 * a number that may not sign in all answer as one invalid code.
 */
class SelfServiceAuthException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    /**
     * @param  array<string, string|int>  $parameters
     */
    public function __construct(
        private readonly string $key,
        public readonly string $apiCode,
        public readonly int $status = 422,
        private readonly array $parameters = [],
    ) {
        parent::__construct(self::englishMessage($this->key, $this->parameters));
    }

    /**
     * The switch is off.
     */
    public static function phoneSignInUnavailable(): self
    {
        return new self('api.error.auth.phone_sign_in_unavailable', 'PHONE_SIGN_IN_UNAVAILABLE', 404);
    }

    /**
     * No live code, a wrong one, or a number that may not sign in this way.
     */
    public static function invalidCode(): self
    {
        return new self('api.error.auth.phone_sign_in_invalid_code', 'PHONE_SIGN_IN_INVALID_CODE', 422);
    }

    /**
     * The code was right and the number has no account: a name is needed to create one.
     * The code is not spent.
     */
    public static function registrationDetailsRequired(): self
    {
        return new self('api.error.auth.registration_details_required', 'REGISTRATION_DETAILS_REQUIRED', 422);
    }

    public static function registrationClosed(): self
    {
        return new self('api.error.auth.registration_closed', 'REGISTRATION_CLOSED', 403);
    }

    public static function captchaFailed(): self
    {
        return new self('api.error.auth.captcha_failed', 'CAPTCHA_FAILED', 422);
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
