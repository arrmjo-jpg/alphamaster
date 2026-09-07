<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Modules\User\Exceptions\InvalidPhoneNumberException;
use App\Modules\User\Support\PhoneNumber;

/**
 * What a sign-in attempt names an account by, and how two attempts are recognised
 * as naming the same one.
 *
 * The login endpoint accepts an email address or a phone number in one field. That
 * leaves two questions, and they have to be answered the same way in both places
 * that ask them — the lookup, and the throttle key — or the two disagree about what
 * "the same account" means.
 *
 * The consequence of disagreeing is concrete. The throttle counts failures per
 * identifier, so if `+962 79 000 0000` and `+962790000000` produce different keys,
 * an attacker varies the spacing and gets a fresh allowance for every attempt while
 * the lookup happily resolves all of them to one account. Canonicalising here, once,
 * is what stops the limiter from being bypassed by whitespace.
 */
final class LoginIdentifier
{
    /**
     * Whether this identifier names an email address rather than a phone number.
     *
     * `@` is the whole test, and it is sufficient: E.164 admits `+` and digits only,
     * so no phone number can contain one, and no email address can omit one. It is
     * deliberately not a validity check — deciding which column to look in is a
     * different question from whether the value is well-formed, and the endpoint
     * answers the second one with the same refusal either way.
     */
    public static function isEmail(string $identifier): bool
    {
        return str_contains($identifier, '@');
    }

    /**
     * The stable form of an identifier, for anything that has to recognise two
     * attempts as being against the same account.
     *
     * A value that is neither a readable phone number nor an email is returned
     * lowercased rather than rejected. It still needs a throttle key: an attacker
     * enumerating nonsense is exactly who the limiter is for, and raising here would
     * make malformed input cheaper to send than well-formed input.
     */
    public static function canonicalise(string $identifier): string
    {
        if (self::isEmail($identifier)) {
            return mb_strtolower($identifier);
        }

        try {
            return PhoneNumber::canonicalise($identifier) ?? mb_strtolower($identifier);
        } catch (InvalidPhoneNumberException) {
            return mb_strtolower($identifier);
        }
    }
}
