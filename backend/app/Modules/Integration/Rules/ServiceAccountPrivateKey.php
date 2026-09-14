<?php

declare(strict_types=1);

namespace App\Modules\Integration\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The private key of a Google service account, as Firebase issues it.
 *
 * The rule is OpenSSL itself rather than a length or a pattern alone. The driver signs
 * a JWT with this key on every token exchange (FcmAccessToken), so the only useful
 * question at save time is the one it will ask later: can this be loaded as an RSA
 * private key? A value that passes a regular expression and fails to load would be
 * accepted here and surface as a failed push an hour later, on a queue worker, with no
 * operator watching.
 *
 * What it accepts is exactly what Google issues: a PKCS#8 PEM block, RSA, at least 2048
 * bits. Newlines may arrive escaped — a service-account file pasted through a form keeps
 * the `\n` of its JSON encoding — and are read the way the driver reads them, without
 * the stored value being rewritten.
 *
 * Nothing about the value leaves this class: the failure message names the field and
 * never quotes it, and OpenSSL's error queue is drained on every path, because a message
 * left there is reported by the next unrelated openssl call and could reach a log.
 */
final class ServiceAccountPrivateKey implements ValidationRule
{
    /**
     * The ceiling, sized to the credential rather than chosen to be large.
     *
     * A 2048-bit PKCS#8 key — what Firebase issues — is about 1,700 characters, and a
     * 4096-bit one about 3,300. Twice the larger leaves room for escaped newlines and
     * stops anything that is not a key long before it reaches OpenSSL.
     */
    public const MAX_LENGTH = 8192;

    private const MINIMUM_BITS = 2048;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isUsable($value)) {
            $fail(__('validation.custom.credentials.private_key.pem'));
        }
    }

    /**
     * Whether the value loads as an RSA private key of at least 2048 bits.
     */
    public static function isUsable(string $value): bool
    {
        if (strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        // Restored before trimming: an escaped trailing newline is only whitespace once
        // it is a newline again.
        $pem = trim(str_replace(['\\n', "\r\n"], "\n", $value));

        // `-{5}` rather than five literal dashes: the repository's secret scan reads a
        // literal PEM header as a committed key, and this pattern is not one.
        if (preg_match('/\A-{5}BEGIN PRIVATE KEY-{5}\n[A-Za-z0-9+\/=\n]+-{5}END PRIVATE KEY-{5}\z/', $pem) !== 1) {
            return false;
        }

        $key = openssl_pkey_get_private($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        while (openssl_error_string() !== false) {
            // Drained, never read: see the class docblock.
        }

        return is_array($details)
            && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && ($details['bits'] ?? 0) >= self::MINIMUM_BITS;
    }
}
