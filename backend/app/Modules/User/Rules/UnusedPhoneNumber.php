<?php

declare(strict_types=1);

namespace App\Modules\User\Rules;

use App\Modules\User\Exceptions\InvalidPhoneNumberException;
use App\Modules\User\Models\User;
use App\Modules\User\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number no other account holds.
 *
 * Compared on the keyed lookup digest, the way the unique constraint compares, so
 * `+962 79 000 0000` and `+962790000000` are one number here as they are in the
 * database. An unreadable number is left to `ReadablePhoneNumber` to report.
 */
final class UnusedPhoneNumber implements ValidationRule
{
    public function __construct(private readonly ?string $ignoreAccountId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            $canonical = PhoneNumber::canonicalise($value);
        } catch (InvalidPhoneNumberException) {
            return;
        }

        if ($canonical === null) {
            return;
        }

        $taken = User::query()
            ->where('phone_hash', PhoneNumber::lookupHash($canonical))
            ->when($this->ignoreAccountId !== null, fn ($query) => $query->whereKeyNot($this->ignoreAccountId))
            ->exists();

        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
