<?php

declare(strict_types=1);

namespace App\Modules\User\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * A link or unlink the rules of ADR 0050 §5 refuse.
 *
 * One exception with a reason rather than five classes: the consumer answers each reason
 * with its own code and status, and a class per reason would be five places to keep in
 * step with one list.
 */
class SocialIdentityException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    /** The account is an administrator, which never holds a linked identity. */
    public const ADMINISTRATOR = 'administrator';

    /** The subject belongs, or belonged, to another account. */
    public const IDENTITY_IN_USE = 'identity_in_use';

    /** The account already has a linked identity from this provider. */
    public const PROVIDER_ALREADY_LINKED = 'provider_already_linked';

    /** Unlinking would leave the account with no way to sign in. */
    public const LAST_SIGN_IN_METHOD = 'last_sign_in_method';

    /** The identity is not a linked identity of this account. */
    public const NOT_LINKED = 'not_linked';

    private function __construct(public readonly string $reason)
    {
        parent::__construct(self::englishMessage($this->translationKey()));
    }

    public static function administrator(): self
    {
        return new self(self::ADMINISTRATOR);
    }

    public static function identityInUse(): self
    {
        return new self(self::IDENTITY_IN_USE);
    }

    public static function providerAlreadyLinked(): self
    {
        return new self(self::PROVIDER_ALREADY_LINKED);
    }

    public static function lastSignInMethod(): self
    {
        return new self(self::LAST_SIGN_IN_METHOD);
    }

    public static function notLinked(): self
    {
        return new self(self::NOT_LINKED);
    }

    public function translationKey(): string
    {
        return 'api.error.user.social_identity_'.$this->reason;
    }
}
