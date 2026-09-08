<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * How an access token reaches a browser, and how it is taken back (ADR 0042).
 *
 * The token itself is unchanged: a real Sanctum PersonalAccessToken carrying exactly
 * one ability, exactly as ADR 0012 requires. This is transport and nothing else. A
 * browser has no safe place to keep a bearer token — localStorage and sessionStorage
 * both hand it to any script on the page — and this credential opens an
 * administrative API.
 *
 * ## The four attributes, and why each is that value
 *
 * `HttpOnly` is the whole point: script cannot read the cookie, so an injected script
 * cannot exfiltrate the credential for use elsewhere and later. It is worth being
 * exact about the limit — the browser still attaches the cookie to requests the page
 * makes, so a script can still *act* as the user while the page is open. The
 * reduction is real and it is not total.
 *
 * `Secure` keeps a credential off plaintext transport. Browsers treat `http://localhost`
 * as a trustworthy origin for cookie purposes, so this does not have to be relaxed for
 * local development, and it is not.
 *
 * `SameSite=Strict` is the CSRF defence. A cookie the browser refuses to attach to a
 * cross-site request cannot be used by one, which is why this needs no synchroniser
 * token. It is available only because the Admin is served from the API's own origin
 * (ADR 0042); a cross-origin Admin would have required `None` and a CSRF token —
 * more machinery, and weaker.
 *
 * `Path=/api` sends it to the API and to nothing else, including the static assets
 * served from the same origin.
 *
 * ## Not encrypted, deliberately
 *
 * Laravel encrypts cookies through EncryptCookies, which lives in the `web` middleware
 * group; API routes have neither it nor AddQueuedCookiesToResponse. That means the
 * cookie is written and read raw, and the cookie is attached directly to a response
 * rather than queued.
 *
 * Encrypting it would buy nothing. The value is the same plaintext token that would
 * otherwise sit in an Authorization header, the server needs the plaintext to resolve
 * it, and the holder is the account it belongs to. Adding the `web` group to API
 * routes to gain the encryption would drag in session handling and CSRF machinery
 * this API deliberately does not use.
 */
final class AuthCookie
{
    /**
     * Prefixed with `__Host-` deliberately. A browser accepts that prefix only on a
     * cookie that is Secure, has no Domain, and is pathed at `/` — which this is not,
     * so the prefix is *not* used. The name is plain, and the attributes below are
     * what carry the guarantees.
     */
    public const NAME = 'alphamaster_token';

    public const PATH = '/api';

    /**
     * The cookie carrying an issued token.
     *
     * Its lifetime is the configured session lifetime rather than the token's, and
     * those are different things on purpose. A Sanctum token here does not expire
     * (`sanctum.expiration` is null); the cookie is how long a browser should keep
     * presenting it without the operator signing in again, which is exactly what
     * `auth.session_lifetime` already means.
     */
    public static function issue(string $plainTextToken): Cookie
    {
        return self::make($plainTextToken, self::lifetimeMinutes() * 60);
    }

    /**
     * The cookie that removes it: same name, same path, empty and already expired.
     *
     * Path has to match. A browser scopes a cookie by name *and* path, so a deletion
     * written at a different path leaves the original in place and sign-out silently
     * does nothing.
     */
    public static function forget(): Cookie
    {
        return self::make('', -3600);
    }

    private static function make(string $value, int $maxAge): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue($value)
            ->withExpires($maxAge === 0 ? 0 : time() + $maxAge)
            ->withPath(self::PATH)
            ->withDomain(null)
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withRaw(false)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    private static function lifetimeMinutes(): int
    {
        $configured = setting('auth.session_lifetime', 120);

        return is_int($configured) && $configured > 0 ? $configured : 120;
    }
}
