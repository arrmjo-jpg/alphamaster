<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\SocialLogin;

use App\Modules\Integration\Exceptions\SocialProviderException;
use Closure;

/**
 * Validates an OpenID Connect ID token (ADR 0050 §8).
 *
 * `openssl_verify` rather than a JWT library, for the reason FcmAccessToken gives for
 * signing without one: the algorithm is fixed, the claim set is small, and a general
 * library brings a hundred features to do what this does — several of them, historically,
 * ways to accept a token that should have been refused.
 *
 * Which is why the algorithm is not negotiated. The header must say RS256 and nothing
 * else: a token declaring `none` or an HMAC algorithm is refused before any key is looked
 * up, so a public key can never be misused as a shared secret.
 *
 * Every refusal names the check that failed. None carries the token.
 */
final class IdTokenVerifier
{
    /**
     * Clock skew tolerated on time claims, in seconds.
     */
    public const LEEWAY_SECONDS = 60;

    /**
     * @param  Closure(string): ?string  $publicKeyFor  a key id to its PEM public key, or null
     * @param  array<int, string>  $issuers
     * @return array<string, mixed>
     *
     * @throws SocialProviderException
     */
    public function verify(string $token, Closure $publicKeyFor, array $issuers, string $audience, string $nonce): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw $this->refusal('ID_TOKEN_MALFORMED', 'The ID token is not a signed JWT.');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $segments;

        $header = $this->decodeObject($encodedHeader);
        $claims = $this->decodeObject($encodedClaims);
        $signature = RsaPublicKey::base64UrlDecode($encodedSignature);

        if ($header === null || $claims === null || $signature === null || $signature === '') {
            throw $this->refusal('ID_TOKEN_MALFORMED', 'The ID token could not be decoded.');
        }

        if (($header['alg'] ?? null) !== 'RS256') {
            throw $this->refusal('ID_TOKEN_ALGORITHM', 'The ID token is not signed with RS256.');
        }

        $keyId = $header['kid'] ?? null;
        $pem = is_string($keyId) && $keyId !== '' ? $publicKeyFor($keyId) : null;
        $key = $pem === null ? false : openssl_pkey_get_public($pem);

        if ($key === false) {
            throw $this->refusal('ID_TOKEN_KEY_UNKNOWN', 'The ID token was signed with a key the provider does not publish.');
        }

        if (openssl_verify($encodedHeader.'.'.$encodedClaims, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw $this->refusal('ID_TOKEN_SIGNATURE', 'The ID token signature is not valid.');
        }

        if (! in_array($claims['iss'] ?? null, $issuers, true)) {
            throw $this->refusal('ID_TOKEN_ISSUER', 'The ID token was issued by an unexpected issuer.');
        }

        $declared = $claims['aud'] ?? null;
        $audiences = is_string($declared) ? [$declared] : (is_array($declared) ? $declared : []);

        // With more than one audience the token must also say it was issued to this
        // client, or a token minted for another client that happened to list this one
        // would be accepted (OpenID Connect Core §3.1.3.7). And whenever it names the
        // party it was issued to, that party must be this client: this platform redeems
        // its own authorization codes, so a token authorized for any other client — an
        // app's, say — was not produced by this sign-in.
        $authorizedParty = $claims['azp'] ?? null;

        if (! in_array($audience, $audiences, true)
            || (count($audiences) > 1 && $authorizedParty !== $audience)
            || ($authorizedParty !== null && $authorizedParty !== $audience)) {
            throw $this->refusal('ID_TOKEN_AUDIENCE', 'The ID token was not issued to this client.');
        }

        $now = time();
        $expires = $claims['exp'] ?? null;

        if (! is_int($expires) || $expires < $now - self::LEEWAY_SECONDS) {
            throw $this->refusal('ID_TOKEN_EXPIRED', 'The ID token has expired.');
        }

        $issuedAt = $claims['iat'] ?? null;
        $notBefore = $claims['nbf'] ?? null;

        if ((isset($claims['iat']) && (! is_int($issuedAt) || $issuedAt > $now + self::LEEWAY_SECONDS))
            || (isset($claims['nbf']) && (! is_int($notBefore) || $notBefore > $now + self::LEEWAY_SECONDS))) {
            throw $this->refusal('ID_TOKEN_NOT_YET_VALID', 'The ID token is not valid yet.');
        }

        $presented = $claims['nonce'] ?? null;

        if (! is_string($presented) || ! hash_equals($nonce, $presented)) {
            throw $this->refusal('ID_TOKEN_NONCE', 'The ID token does not belong to this sign-in.');
        }

        $subject = $claims['sub'] ?? null;

        if (! is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw $this->refusal('ID_TOKEN_SUBJECT', 'The ID token carries no usable subject.');
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeObject(string $segment): ?array
    {
        $json = RsaPublicKey::base64UrlDecode($segment);

        if ($json === null) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    private function refusal(string $code, string $message): SocialProviderException
    {
        return new SocialProviderException($code, $message);
    }
}
