<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\SocialLogin;

/**
 * An RSA public key published as a JSON Web Key, in the PEM form OpenSSL reads.
 *
 * A JWK gives the modulus and exponent as base64url integers. OpenSSL wants a
 * SubjectPublicKeyInfo structure, so this writes the handful of DER bytes around them:
 *
 *     SEQUENCE {
 *       SEQUENCE { OBJECT IDENTIFIER rsaEncryption, NULL }
 *       BIT STRING { SEQUENCE { INTEGER modulus, INTEGER exponent } }
 *     }
 *
 * Written out rather than taken from a library for the reason IdTokenVerifier gives. The
 * result is always parsed back by OpenSSL before it is returned, so a malformed key is
 * refused here rather than accepted and failing somewhere less obvious.
 */
final class RsaPublicKey
{
    /** 1.2.840.113549.1.1.1, rsaEncryption, as DER. */
    private const RSA_ENCRYPTION_OID = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";

    private const DER_NULL = "\x05\x00";

    /**
     * The PEM for a JWK modulus and exponent, or null when they do not form a key.
     */
    public static function pemFromJwk(string $modulus, string $exponent): ?string
    {
        $n = self::base64UrlDecode($modulus);
        $e = self::base64UrlDecode($exponent);

        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }

        $publicKey = self::sequence(self::integer($n).self::integer($e));
        $algorithm = self::sequence(self::RSA_ENCRYPTION_OID.self::DER_NULL);
        // A bit string's first content byte counts the unused bits in its last byte.
        $bitString = "\x03".self::length(strlen($publicKey) + 1)."\x00".$publicKey;

        $der = self::sequence($algorithm.$bitString);

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem) === false ? null : $pem;
    }

    /**
     * Decode base64url, strictly, restoring the padding JWTs and JWKs omit.
     */
    public static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return $value === '' ? '' : null;
        }

        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder === 1) {
            return null;
        }

        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * A DER INTEGER. Unsigned big-endian input gains a leading zero when its high bit is
     * set, so it is not read as negative.
     */
    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        if (ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::length(strlen($bytes)).$bytes;
    }

    private static function sequence(string $content): string
    {
        return "\x30".self::length(strlen($content)).$content;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
