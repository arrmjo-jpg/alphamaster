<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Push;

use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Services\ProviderHttp;
use Illuminate\Support\Facades\Log;

/**
 * A short-lived Google access token, minted from a service account and cached.
 *
 * Its own class because the signing is the fiddly part and it has nothing to do with
 * sending a message: a driver that did both would be two concerns in one file, and the
 * one worth reading in isolation is this one.
 *
 * What it never does is log or return the private key. A failure reports that the
 * exchange failed and, at most, the vendor's own error — the key itself is used inside
 * one function and is not carried anywhere it could be written down.
 */
class FcmAccessToken
{
    public function __construct(private readonly PlatformCacheContract $cache) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function for(
        IntegrationProvider $provider,
        array $credentials,
        string $scope,
        string $endpoint,
        int $ttlSeconds,
    ): ?string {
        // Discriminated by the provider row *and* when it last changed, so rotating a
        // key through the Admin does not leave a token minted from the old one in
        // place — the row's `updated_at` moves and the cached token stops matching.
        $discriminators = [
            'provider' => $provider->id,
            'rotated' => (string) ($provider->updated_at?->getTimestamp() ?? 0),
        ];

        $held = $this->cache->get(CacheNamespace::INTEGRATION, 'fcm-access-token', $discriminators);

        if (is_string($held) && $held !== '') {
            return $held;
        }

        $assertion = $this->assertion($credentials, $scope, $endpoint);

        if ($assertion === null) {
            return null;
        }

        try {
            $response = ProviderHttp::client(10)->asForm()->post($endpoint, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
        } catch (\Throwable $e) {
            Log::warning('A Firebase access token could not be obtained.', ['reason' => $e->getMessage()]);

            return null;
        }

        $token = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($token) || $token === '') {
            Log::warning('Firebase refused the service-account assertion.', [
                // The vendor's own words. Never the assertion, which is signed with the
                // private key, and never the key.
                'status' => $response->status(),
                'error' => (string) ($response->json('error') ?? ''),
            ]);

            return null;
        }

        $this->cache->put(CacheNamespace::INTEGRATION, 'fcm-access-token', $discriminators, $token, $ttlSeconds);

        return $token;
    }

    /**
     * The signed JWT Google exchanges for a token.
     *
     * `openssl_sign` rather than a JWT library: the claim set is four fields and the
     * algorithm is fixed, so a dependency would carry a hundred features to do what
     * twenty lines do, and every one of them would be code holding a private key.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function assertion(array $credentials, string $scope, string $audience): ?string
    {
        $email = (string) ($credentials['client_email'] ?? '');
        $key = (string) ($credentials['private_key'] ?? '');

        if ($email === '' || $key === '') {
            return null;
        }

        $now = time();

        $header = $this->encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = $this->encode([
            'iss' => $email,
            'scope' => $scope,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $signature = '';

        // The key arrives from JSON with escaped newlines when an operator pasted the
        // file through a form that mangled them. Restored here rather than at the point
        // of storage, because what was stored is what the operator gave us.
        $pem = str_replace('\\n', "\n", $key);

        if (! openssl_sign($header.'.'.$claims, $signature, $pem, OPENSSL_ALGO_SHA256)) {
            Log::warning('The Firebase service-account key could not sign an assertion.');

            return null;
        }

        return $header.'.'.$claims.'.'.$this->base64Url($signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return $this->base64Url((string) json_encode($payload));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
