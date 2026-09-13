<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\SocialLogin;

use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\Integration\Services\ProviderHttp;
use Throwable;

/**
 * The public keys Google signs ID tokens with, fetched and cached.
 *
 * Public material, published by Google, and cached in the Integration namespace because
 * it is vendor material a driver derives rather than stores (ADR 0045).
 *
 * An unknown key id is how key rotation shows itself, so it triggers one fresh fetch. It
 * does not trigger one per request: a token naming a key id nobody publishes would
 * otherwise turn every forged sign-in attempt into a request to Google, so a fetch made
 * because of an unknown key is not repeated for a minute.
 */
class GoogleSigningKeys
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const KEYS_RESOURCE = 'google-signing-keys';

    private const REFRESH_RESOURCE = 'google-signing-keys-refreshed';

    private const DEFAULT_TTL_SECONDS = 3600;

    private const MAX_TTL_SECONDS = 86400;

    private const REFRESH_COOLDOWN_SECONDS = 60;

    public function __construct(private readonly PlatformCacheContract $cache) {}

    /**
     * The PEM public key for a key id, or null when Google does not publish it.
     */
    public function publicKey(string $keyId): ?string
    {
        $held = $this->cache->get(CacheNamespace::INTEGRATION, self::KEYS_RESOURCE);

        if (is_array($held) && isset($held[$keyId]) && is_string($held[$keyId])) {
            return $held[$keyId];
        }

        if (is_array($held) && $this->cache->get(CacheNamespace::INTEGRATION, self::REFRESH_RESOURCE) !== null) {
            return null;
        }

        $this->cache->put(CacheNamespace::INTEGRATION, self::REFRESH_RESOURCE, [], true, self::REFRESH_COOLDOWN_SECONDS);

        $fresh = $this->fetch();

        return $fresh[$keyId] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function fetch(): array
    {
        try {
            $response = ProviderHttp::client()->acceptJson()->get(self::JWKS_URL);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $published = $response->json('keys');
        $keys = [];

        foreach (is_array($published) ? $published : [] as $jwk) {
            if (! is_array($jwk)
                || ($jwk['kty'] ?? null) !== 'RSA'
                || ! is_string($jwk['kid'] ?? null)
                || ! is_string($jwk['n'] ?? null)
                || ! is_string($jwk['e'] ?? null)
                || (isset($jwk['use']) && $jwk['use'] !== 'sig')
                || (isset($jwk['alg']) && $jwk['alg'] !== 'RS256')) {
                continue;
            }

            $pem = RsaPublicKey::pemFromJwk($jwk['n'], $jwk['e']);

            if ($pem !== null) {
                $keys[$jwk['kid']] = $pem;
            }
        }

        if ($keys !== []) {
            $this->cache->put(
                CacheNamespace::INTEGRATION,
                self::KEYS_RESOURCE,
                [],
                $keys,
                $this->lifetime((string) $response->header('Cache-Control')),
            );
        }

        return $keys;
    }

    /**
     * How long Google says the keys may be kept, within sensible bounds.
     */
    private function lifetime(string $cacheControl): int
    {
        if (preg_match('/max-age=(\d+)/', $cacheControl, $match) !== 1) {
            return self::DEFAULT_TTL_SECONDS;
        }

        return max(60, min((int) $match[1], self::MAX_TTL_SECONDS));
    }
}
