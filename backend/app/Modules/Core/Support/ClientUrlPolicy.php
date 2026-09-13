<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Whether an address an operator configured is one the platform may send a person to.
 *
 * Two settings hold such addresses today: the page a password reset link opens, and the
 * addresses a social sign-in may return to (ADR 0050 §11, §12). Both are named by an
 * operator, because no frontend domain is assumed — and both are exactly where a
 * mistyped or leftover value hurts: a reset link to a developer's machine, or an
 * authorization code delivered somewhere nobody meant.
 *
 * One policy, used when the value is written and again when it is used. Validation
 * alone would not do: a value saved before the environment became production, or
 * restored from a backup, never passed this environment's rules, and the platform must
 * not act on it just because it is stored.
 *
 * ## What is refused everywhere
 *
 * Anything that is not a complete address with a scheme (and, for the web, a host); a
 * fragment, which a redirect would lose and a reset link would break; credentials in the
 * address; and a wildcard, because these addresses are matched exactly and a pattern
 * reads as a promise the platform does not keep.
 *
 * ## What is refused in production
 *
 * Plain http, and any address that points at this machine — `localhost`, `*.localhost`
 * or a loopback address. There is no production fallback to either: an unusable value is
 * treated as no value.
 *
 * ## App schemes
 *
 * A redirect URI may be a native app's private-use scheme, in reverse-domain form
 * (`com.example.app:/callback`), as RFC 8252 requires of them. Schemes a browser gives
 * other meanings to — `javascript`, `data`, `file` and the like — are refused. A reset
 * page is always a web page.
 */
final class ClientUrlPolicy
{
    public const MAX_LENGTH = 2048;

    public const NOT_TEXT = 'not_text';

    public const NOT_ABSOLUTE = 'not_absolute';

    public const TOO_LONG = 'too_long';

    public const SCHEME = 'scheme';

    public const FRAGMENT = 'fragment';

    public const USERINFO = 'userinfo';

    public const WILDCARD = 'wildcard';

    public const INSECURE = 'insecure';

    public const LOOPBACK = 'loopback';

    private const WEB_SCHEMES = ['http', 'https'];

    private const FORBIDDEN_SCHEMES = [
        'javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'filesystem',
        'ftp', 'ws', 'wss', 'mailto', 'tel', 'sms',
    ];

    public function __construct(private readonly bool $production) {}

    public static function forCurrentEnvironment(): self
    {
        return new self(app()->isProduction());
    }

    /**
     * What is wrong with a web page address, or null when it may be used.
     */
    public function pageUrlProblem(mixed $url): ?string
    {
        return $this->inspect($url, allowAppScheme: false);
    }

    /**
     * What is wrong with a sign-in return address, or null when it may be used.
     */
    public function redirectUriProblem(mixed $uri): ?string
    {
        return $this->inspect($uri, allowAppScheme: true);
    }

    private function inspect(mixed $value, bool $allowAppScheme): ?string
    {
        if (! is_string($value)) {
            return self::NOT_TEXT;
        }

        if (strlen($value) > self::MAX_LENGTH) {
            return self::TOO_LONG;
        }

        if ($value === '' || preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1) {
            return self::NOT_ABSOLUTE;
        }

        if (str_contains($value, '*')) {
            return self::WILDCARD;
        }

        if (str_contains($value, '#')) {
            return self::FRAGMENT;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'])) {
            return self::NOT_ABSOLUTE;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::USERINFO;
        }

        $scheme = strtolower($parts['scheme']);

        if (in_array($scheme, self::WEB_SCHEMES, true)) {
            $host = rtrim(strtolower(trim($parts['host'] ?? '', '[]')), '.');

            if ($host === '' || ! str_starts_with(strtolower($value), $scheme.'://')) {
                return self::NOT_ABSOLUTE;
            }

            if ($this->production && $this->isLoopback($host)) {
                return self::LOOPBACK;
            }

            if ($this->production && $scheme !== 'https') {
                return self::INSECURE;
            }

            return null;
        }

        if (! $allowAppScheme
            || in_array($scheme, self::FORBIDDEN_SCHEMES, true)
            || preg_match('/^[a-z][a-z0-9+\-]*(\.[a-z0-9+\-]+)+$/', $scheme) !== 1) {
            return self::SCHEME;
        }

        return null;
    }

    private function isLoopback(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.') || $host === '0.0.0.0';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($host);

            return $packed === inet_pton('::1') || $packed === inet_pton('::');
        }

        return false;
    }
}
