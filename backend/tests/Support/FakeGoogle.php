<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Google, as far as a test needs it to be: an OpenID Connect provider that signs real ID
 * tokens with a real RSA key and publishes the public half.
 *
 * Nothing about the validation is mocked. The platform fetches these keys over the
 * faked HTTP client, converts them from JWK to PEM itself, and checks the signature with
 * OpenSSL — so a test that passes has exercised the same code a production sign-in runs.
 *
 * The keys are generated when first asked for and never written anywhere. No private key
 * lives in the repository.
 */
final class FakeGoogle
{
    public const CLIENT_ID = 'platform-test-client.apps.googleusercontent.com';

    public const CLIENT_CREDENTIAL = 'platform-test-client-credential';

    public const REDIRECT_URI = 'https://client.example.test/auth/social/callback';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const KEYS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public const KEY_ID = 'platform-test-signing-key';

    /** An access token Google would send, which the platform must never keep. */
    public const ISSUED_ACCESS_TOKEN = 'provider-access-token-that-is-never-kept';

    private static ?OpenSSLAsymmetricKey $signingKey = null;

    private static ?OpenSSLAsymmetricKey $otherKey = null;

    /**
     * Switch social login on and configure the Google provider, the way an operator would.
     */
    public static function configure(
        bool $enabled = true,
        bool $active = true,
        bool $withCredential = true,
        string $clientId = self::CLIENT_ID,
    ): IntegrationProvider {
        $settings = app(SettingServiceInterface::class);
        $settings->set('auth', 'social_login_enabled', $enabled);
        $settings->set('auth', 'social_redirect_uris', [self::REDIRECT_URI]);

        $provider = IntegrationProvider::query()
            ->forCapability(IntegrationCapability::SOCIAL_LOGIN)
            ->where('driver', 'google')
            ->firstOrFail();

        $provider->setCredentials($withCredential ? ['client_secret' => self::CLIENT_CREDENTIAL] : null);
        $provider->forceFill(['is_active' => $active, 'settings' => ['client_id' => $clientId]])->save();

        Cache::flush();

        return $provider->refresh();
    }

    /**
     * A PKCE verifier, as a client generates one.
     */
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(48));
    }

    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    /**
     * Begin a flow through the API and read back what the platform sent Google.
     *
     * @return array{state: string, nonce: string, verifier: string, url: string}
     */
    public static function authorize(mixed $test, string $path = '/api/v1/auth/social/google/authorize', ?string $token = null): array
    {
        $verifier = self::verifier();
        $client = $token === null ? $test : $test->withToken($token);

        $response = $client->postJson($path, [
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);

        $response->assertOk();

        $url = (string) $response->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [
            'state' => (string) ($query['state'] ?? ''),
            'nonce' => (string) ($query['nonce'] ?? ''),
            'verifier' => $verifier,
            'url' => $url,
        ];
    }

    /**
     * The claims Google puts in an ID token, for a person.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function claims(string $nonce, array $overrides = []): array
    {
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'azp' => self::CLIENT_ID,
            'aud' => self::CLIENT_ID,
            'sub' => '110248495921238986420',
            'email' => 'person@example.test',
            'email_verified' => true,
            'name' => 'Test Person',
            'iat' => time(),
            'exp' => time() + 3600,
            'nonce' => $nonce,
        ], $overrides);
    }

    /**
     * A signed ID token.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $header
     */
    public static function idToken(array $claims, array $header = [], ?OpenSSLAsymmetricKey $key = null): string
    {
        $header = array_merge(['alg' => 'RS256', 'kid' => self::KEY_ID, 'typ' => 'JWT'], $header);
        $signingInput = self::encode($header).'.'.self::encode($claims);

        openssl_sign($signingInput, $signature, $key ?? self::signingKey(), OPENSSL_ALGO_SHA256);

        return $signingInput.'.'.self::base64Url((string) $signature);
    }

    /**
     * Fake Google redeeming a code for this ID token, and publishing its signing key.
     */
    public static function respondWith(string $idToken): void
    {
        self::answer(200, [
            'access_token' => self::ISSUED_ACCESS_TOKEN,
            'expires_in' => 3599,
            'token_type' => 'Bearer',
            'scope' => 'openid email profile',
            'id_token' => $idToken,
        ]);
    }

    /**
     * Fake Google refusing to redeem the code.
     */
    public static function refuseCode(): void
    {
        self::answer(400, [
            'error' => 'invalid_grant',
            'error_description' => 'Bad Request with detail that must not be copied anywhere',
        ]);
    }

    /**
     * Set what the token endpoint answers next.
     *
     * The HTTP client keeps every fake it is given and the first that matches wins, so a
     * test that signs in twice cannot simply fake again. The stub is registered once per
     * application and reads the answer set most recently.
     *
     * @param  array<string, mixed>  $body
     */
    private static function answer(int $status, array $body): void
    {
        $app = app();
        $registered = $app->bound(self::class.'.faked');

        $app->instance(self::class.'.answer', ['status' => $status, 'body' => $body]);

        if ($registered) {
            return;
        }

        $app->instance(self::class.'.faked', true);

        Http::fake([
            self::TOKEN_URL => static function () use ($app) {
                ['status' => $status, 'body' => $body] = $app->make(self::class.'.answer');

                return Http::response($body, $status);
            },
            self::KEYS_URL => Http::response(['keys' => [self::publishedKey()]], 200, [
                'Cache-Control' => 'public, max-age=3600',
            ]),
        ]);
    }

    /**
     * The public half of the signing key, as Google publishes it.
     *
     * @return array{kty: string, kid: string, use: string, alg: string, n: string, e: string}
     */
    public static function publishedKey(): array
    {
        $details = openssl_pkey_get_details(self::signingKey());

        return [
            'kty' => 'RSA',
            'kid' => self::KEY_ID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::base64Url((string) ($details['rsa']['n'] ?? '')),
            'e' => self::base64Url((string) ($details['rsa']['e'] ?? '')),
        ];
    }

    public static function signingKey(): OpenSSLAsymmetricKey
    {
        return self::$signingKey ??= self::generate();
    }

    /**
     * A second key, which Google never published.
     */
    public static function otherKey(): OpenSSLAsymmetricKey
    {
        return self::$otherKey ??= self::generate();
    }

    /**
     * How many times the platform asked Google to redeem a code.
     */
    public static function tokenRequests(): int
    {
        return count(Http::recorded(static fn (Request $request): bool => $request->url() === self::TOKEN_URL));
    }

    public static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function generate(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            throw new RuntimeException('An RSA key could not be generated for the test provider.');
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function encode(array $value): string
    {
        return self::base64Url((string) json_encode($value));
    }
}
