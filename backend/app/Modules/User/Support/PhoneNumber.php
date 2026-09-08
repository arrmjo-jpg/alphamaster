<?php

declare(strict_types=1);

namespace App\Modules\User\Support;

use App\Modules\User\Exceptions\InvalidPhoneNumberException;

/**
 * The canonical form of a phone number, and the value the database looks one up by.
 *
 * The two operations live together because the order between them is load-bearing: a
 * number is hashed only after it has been canonicalised. "+962 79 000 0000" and
 * "+962790000000" are one number and two different digests, so a unique constraint
 * over an uncanonicalised hash would happily store the same number twice, and a
 * lookup would miss a row that is present.
 *
 * Canonicalisation is strict rather than clever. It removes the separators an operator
 * types for legibility and then requires E.164 exactly; it does not guess a country
 * code, and it does not repair a number it cannot read. A rejected number is a
 * correctable mistake at the point of entry. A silently repaired one is a wrong number
 * in the database that nobody finds until a message goes to a stranger.
 *
 * No country-specific validation, and no libphonenumber. This checks the shape E.164
 * defines — a leading `+`, a country code that cannot start with zero, 8 to 15 digits
 * — and not whether that prefix is an allocated range or the subscriber part is the
 * right length for it. That is a deliberate floor, not an oversight: it needs no
 * dependency and no periodically-refreshed metadata table, and it is sufficient for a
 * value used as a login identifier, which is proven correct by the account owner
 * signing in rather than by a library.
 */
final class PhoneNumber
{
    /**
     * Separators a person types and E.164 does not carry, including the non-breaking
     * and narrow no-break spaces that arrive when a number is pasted from a document.
     */
    private const SEPARATORS = [
        ' ', "\t", "\r", "\n", "\u{00A0}", "\u{202F}", "\u{2007}",
        '-', "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}",
        '(', ')', '.', '/', "\u{200E}", "\u{200F}",
    ];

    /**
     * E.164: `+`, a country code whose first digit is not zero, and 8 to 15 digits in
     * total. The upper bound is the standard's; the lower one admits the shortest
     * national numbering plans in use.
     */
    private const CANONICAL = '/^\+[1-9]\d{7,14}$/';

    /**
     * The label the lookup hash is taken over, so the digest is specific to this use.
     * A bare HMAC of the number under APP_KEY would collide with any other feature
     * that later keys the same value the same way, and this column carries a unique
     * constraint — a collision there is a refused write, not a silent one.
     */
    private const LOOKUP_LABEL = 'alphamaster/user-phone-lookup/v1';

    /**
     * Reduce a written number to canonical E.164, or to null when there is none.
     *
     * An empty string is null rather than an error: a form that submits a blank field
     * is saying the account has no phone, which is a legitimate state for every
     * account on this platform.
     *
     * @throws InvalidPhoneNumberException when the value is not empty and is not a
     *                                     phone number this can read
     */
    public static function canonicalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $candidate = str_replace(self::SEPARATORS, '', $value);

        if ($candidate === '') {
            return null;
        }

        if (! self::isCanonical($candidate)) {
            throw InvalidPhoneNumberException::notCanonical();
        }

        return $candidate;
    }

    /**
     * Whether a value is already in canonical E.164 form.
     */
    public static function isCanonical(string $value): bool
    {
        return preg_match(self::CANONICAL, $value) === 1;
    }

    /**
     * The deterministic value the unique constraint and the login lookup both use.
     *
     * Keyed with APP_KEY rather than a plain digest, so the column cannot be matched
     * against a precomputed table of every number in a country — the search space for
     * a phone number is small enough that an unkeyed hash of one is barely a hash at
     * all.
     *
     * It is worth being exact about what this does and does not buy today. `phone` is
     * stored in clear beside it, so this adds no confidentiality to a reader who has
     * the row. What it does is make the constraint and the lookup path independent of
     * how `phone` is stored: the login query already matches on this column, so
     * putting `phone` behind encryption later changes the storage and touches neither
     * the index nor the query.
     *
     * @param  string  $canonical  a value that has been through canonicalise()
     *
     * @throws InvalidPhoneNumberException when it has not
     */
    public static function lookupHash(string $canonical): string
    {
        // Refused rather than hashed. Hashing an uncanonicalised value produces a
        // perfectly valid-looking digest that is simply the wrong one, and a wrong
        // digest in a unique column is a duplicate the constraint cannot see.
        if (! self::isCanonical($canonical)) {
            throw InvalidPhoneNumberException::notCanonical();
        }

        return hash_hmac(
            'sha256',
            self::LOOKUP_LABEL.'|'.$canonical,
            (string) config('app.key'),
        );
    }
}
