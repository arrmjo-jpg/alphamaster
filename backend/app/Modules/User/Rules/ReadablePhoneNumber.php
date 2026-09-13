<?php

declare(strict_types=1);

namespace App\Modules\User\Rules;

use App\Modules\User\Exceptions\InvalidPhoneNumberException;
use App\Modules\User\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number this platform can store.
 *
 * The rule is `PhoneNumber::canonicalise()` itself rather than a regular expression
 * beside it. The model writes a number through an accessor that canonicalises and
 * hashes it, so anything that survives canonicalisation is storable and anything that
 * does not would raise out of the accessor — as a 500 at save time rather than a 422
 * beside the field. Re-stating the pattern here would be a second definition of
 * "readable number", and the two would disagree the first time a separator was added
 * to the list the canonicaliser strips.
 *
 * Separators are read, not required: an operator may type `+962 79 000 0000`. A
 * missing country code is refused rather than guessed, because a repaired number is a
 * wrong number nobody finds until a message goes to a stranger.
 */
final class ReadablePhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('validation.custom.phone.regex'));

            return;
        }

        try {
            PhoneNumber::canonicalise($value);
        } catch (InvalidPhoneNumberException) {
            $fail(__('validation.custom.phone.regex'));
        }
    }
}
