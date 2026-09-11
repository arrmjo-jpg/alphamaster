<?php

declare(strict_types=1);

namespace App\Modules\Core\Ai;

/**
 * What a vendor said went wrong, made safe to keep and to show.
 *
 * A vendor's error ends up in the usage log, in a failed translation suggestion and in
 * the Admin's test result — three places whose access rules are nothing like a
 * credential's. Vendors quote back what they were sent: OpenAI repeats a masked form of
 * a key it refused, a transport error can carry a URL, and a body nobody parsed can
 * carry anything. So every message passes through here before it is stored or shown.
 *
 * A filter over the text rather than an allow-list of messages, deliberately. The
 * vendor's own words ("Incorrect API key provided", "No such model") are the diagnosis
 * an operator needs, and a table of known messages would be out of date the first time
 * a vendor rewords one. What is removed is anything shaped like a secret — the
 * provider's own credentials exactly, the pieces of them a vendor quotes, and the
 * formats keys and tokens take — and anything shaped like a raw body.
 */
final class ErrorRedactor
{
    public const REDACTED = '[redacted]';

    /** Long enough to read as a diagnosis, short enough that nothing is a transcript. */
    private const MAX_LENGTH = 300;

    private const FALLBACK_CODE = 'PROVIDER_ERROR';

    /**
     * An Authorization or Proxy-Authorization value: its first word, then — if there is
     * one — either a list of parameters (`Digest username="…", response="…"`) or a single
     * token (`Basic dXNl…`).
     */
    private const AUTHORIZATION = '/\b((?:proxy-)?authorization)(\s*["\']?\s*[:=]\s*["\']?)([^\s"\',;]+)(?:(\s+)((?:[A-Za-z0-9_-]+=(?:"[^"]*"|[^\s,"]*)(?:\s*,\s*[A-Za-z0-9_-]+=(?:"[^"]*"|[^\s,"]*))*)|[A-Za-z0-9._~+\/-]+=*))?/i';

    /**
     * Schemes whose name is kept while their credential goes: those registered with IANA,
     * and the ones vendors use without registering. A first word not listed here may be
     * the credential itself, and is treated as one.
     */
    private const AUTHORIZATION_SCHEMES = [
        'basic', 'bearer', 'concealed', 'digest', 'dpop', 'gnap', 'hoba', 'mutual', 'negotiate',
        'oauth', 'privatetoken', 'scram-sha-1', 'scram-sha-256', 'vapid',
        'token', 'ntlm', 'apikey', 'api-key', 'key', 'aws4-hmac-sha256', 'hawk', 'mac',
        'signature', 'sharedkey', 'googlelogin',
    ];

    /**
     * The other shapes a secret takes, and what each becomes. The harmless half of a
     * pair — "Bearer", "x-api-key=" — is kept, so the message still says what was refused.
     */
    private const PATTERNS = [
        // A bearer token quoted without its header.
        '/\bBearer\s+[^\s"\',;]+/i' => 'Bearer '.self::REDACTED,
        // A named credential in a header, a query string, a form or a JSON fragment.
        '/\b(x-api-key|x-goog-api-key|api[_-]?key|access[_-]?token|client[_-]?secret|private[_-]?key|password)(\s*["\']?\s*[:=]\s*["\']?)[^\s"\',;&}]+/i' => '$1$2'.self::REDACTED,
        '/([?&](?:key|api_key|access_token|token)=)[^&\s"\']+/i' => '$1'.self::REDACTED,
        // Vendor key formats: OpenAI and Anthropic (sk-…, sk-proj-…, sk-ant-…), Google (AIza…).
        '/\b(?:sk|rk|pk)-[A-Za-z0-9_\-]{8,}/' => self::REDACTED,
        '/\bAIza[0-9A-Za-z_\-]{20,}/' => self::REDACTED,
        // A vendor's masked quotation of a key, such as sk-proj-****abcd.
        '/\S*\*{2,}\S*/' => self::REDACTED,
    ];

    /**
     * A message safe to store and to show.
     *
     * @param  array<int, string>  $secrets  values that must never appear — the provider's credentials
     */
    public static function message(?string $message, array $secrets = [], string $fallback = 'The provider returned an error.'): string
    {
        $text = mb_scrub((string) $message);

        // A whole credential payload first, before its line breaks are collapsed.
        $text = (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', self::REDACTED, $text);

        foreach (self::fragments($secrets) as $fragment) {
            $text = str_replace($fragment, self::REDACTED, $text);
        }

        // A raw body is never a message: markup is reduced to its text, and a JSON
        // document to a note that one was there.
        $text = (string) preg_replace('/<[^>]{1,200}>/', ' ', $text);
        $text = (string) preg_replace('/\{\s*".*\}/s', '[body omitted]', $text);

        $text = self::authorization($text);
        $text = (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);

        // Any long run of letters and digits reads as a token, whoever issued it.
        $text = (string) preg_replace_callback(
            '/\b[A-Za-z0-9_\-]{32,}\b/',
            static fn (array $match): string => preg_match('/[A-Za-z]/', $match[0]) === 1 && preg_match('/\d/', $match[0]) === 1
                ? self::REDACTED
                : $match[0],
            $text
        );

        $text = trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $text));

        if ($text === '') {
            return $fallback;
        }

        return mb_strlen($text) > self::MAX_LENGTH
            ? rtrim(mb_substr($text, 0, self::MAX_LENGTH - 1)).'…'
            : $text;
    }

    /**
     * An error code safe to store and to show: an identifier, or a generic one.
     *
     * @param  array<int, string>  $secrets
     */
    public static function code(?string $code, array $secrets = []): string
    {
        $code = trim((string) $code);

        if (preg_match('/^[A-Za-z0-9_.:\-]{1,64}$/', $code) !== 1) {
            return self::FALLBACK_CODE;
        }

        // A code is an identifier. One that reads as a secret is not.
        return self::message($code, $secrets, '') === $code ? $code : self::FALLBACK_CODE;
    }

    /**
     * Whether a value is itself shaped like a credential — a vendor key, a masked key,
     * an authorization value, a named credential or a private key.
     *
     * For input that should never be one, such as a model ID typed beside a key field.
     * The long-token rule is deliberately not applied: it would also refuse long,
     * legitimate identifiers such as dated model names, and a key for the vendors this
     * platform supports carries a prefix the patterns recognise.
     */
    public static function isCredentialShaped(string $value): bool
    {
        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $value) === 1) {
            return true;
        }

        $checked = self::authorization($value);
        $checked = (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $checked);

        return $checked !== $value;
    }

    /**
     * Authorization values, whatever the scheme.
     *
     * A scheme this class knows keeps its name and loses its credential, whether that is
     * a token or a list of parameters. A first word it does not know may be the
     * credential itself — some vendors send a bare key — so it goes, and so does the word
     * after it when that reads as a credential too: parameters, padding, a digit, or
     * eight characters and more. The header's name is kept, so the message still says
     * what was refused.
     */
    private static function authorization(string $text): string
    {
        return (string) preg_replace_callback(self::AUTHORIZATION, static function (array $match): string {
            $name = $match[1];
            $separator = $match[2];
            $first = $match[3];
            $gap = $match[4] ?? '';
            $rest = $match[5] ?? '';

            if (in_array(strtolower($first), self::AUTHORIZATION_SCHEMES, true)) {
                return $name.$separator.$first.($rest === '' ? '' : $gap.self::REDACTED);
            }

            if ($rest === '') {
                return $name.$separator.self::REDACTED;
            }

            $restIsCredential = str_contains($rest, '=')
                || preg_match('/\d/', $rest) === 1
                || mb_strlen($rest) >= 8;

            return $name.$separator.self::REDACTED.$gap.($restIsCredential ? self::REDACTED : $rest);
        }, $text);
    }

    /**
     * Each secret, and the pieces of it a vendor quotes: a vendor that echoes a rejected
     * key shows its first and last characters around a mask. Longest first, so a whole
     * key is replaced before any piece of it.
     *
     * @param  array<int, string>  $secrets
     * @return array<int, string>
     */
    private static function fragments(array $secrets): array
    {
        $fragments = [];

        foreach ($secrets as $secret) {
            $secret = trim($secret);
            $length = mb_strlen($secret);

            // Too short to identify anything, and matching it would redact ordinary words.
            if ($length < 4) {
                continue;
            }

            $fragments[] = $secret;

            if ($length >= 12) {
                $fragments[] = mb_substr($secret, 0, 8);
                $fragments[] = mb_substr($secret, -6);
            }
        }

        $fragments = array_values(array_unique($fragments));
        usort($fragments, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $fragments;
    }
}
