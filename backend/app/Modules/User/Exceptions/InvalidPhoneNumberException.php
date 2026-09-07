<?php

declare(strict_types=1);

namespace App\Modules\User\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a value cannot be reduced to a canonical E.164 number.
 *
 * Deliberately not a LocalizableException. This guards the column rather than
 * answering a request: it fires where a number is written to the model, so reaching
 * it means something upstream skipped validation, and the honest report of that is a
 * failure rather than a translated sentence. The request layer validates the
 * identifier it accepts and produces its own message; this is the floor beneath it.
 *
 * The rejected value is not carried in the message. A phone number is personal data
 * and this exception ends up in logs.
 */
class InvalidPhoneNumberException extends InvalidArgumentException
{
    public static function notCanonical(): self
    {
        return new self('The value is not a canonical E.164 phone number.');
    }
}
