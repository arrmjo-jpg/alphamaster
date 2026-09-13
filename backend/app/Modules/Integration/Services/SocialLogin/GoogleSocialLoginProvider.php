<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\SocialLogin;

use App\Modules\Integration\Contracts\SocialLoginProviderContract;
use App\Modules\Integration\Data\SocialAuthorizationRequest;
use App\Modules\Integration\Data\SocialCodeExchange;
use App\Modules\Integration\Data\SocialProfile;
use App\Modules\Integration\Exceptions\SocialProviderException;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Services\ProviderHttp;
use Throwable;

/**
 * Google, as an OpenID Connect provider, over the HTTP client rather than an SDK.
 *
 * Written the way every other vendor driver here is: no added dependency, faithful to
 * the vendor's documented request and error shape, and exercisable through Http::fake().
 *
 * The flow is authorization code with PKCE. The client holds the verifier and sends
 * only its S256 challenge, which Google checks when the code is redeemed. Google is
 * also given a nonce, which comes back inside the ID token and is compared with the one
 * the platform stored, so an ID token cannot be replayed into a different flow.
 *
 * The access token Google returns is not read. Identity comes from the validated ID
 * token alone, and nothing Google sends is kept beyond the subject and what the platform
 * shows as a snapshot.
 */
class GoogleSocialLoginProvider implements SocialLoginProviderContract
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Google documents both forms of its issuer, and signs with either.
     *
     * @var array<int, string>
     */
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    private const SCOPES = 'openid email profile';

    public function __construct(
        private readonly GoogleSigningKeys $keys,
        private readonly IdTokenVerifier $verifier,
    ) {}

    public function driver(): string
    {
        return 'google';
    }

    public function authorizationUrl(SocialAuthorizationRequest $request, IntegrationProvider $provider): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId($provider),
            'redirect_uri' => $request->redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $request->state,
            'nonce' => $request->nonce,
            'code_challenge' => $request->codeChallenge,
            'code_challenge_method' => 'S256',
            // A person with more than one Google account is asked which one, rather than
            // silently signed in as whichever the browser last used.
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(SocialCodeExchange $exchange, IntegrationProvider $provider): SocialProfile
    {
        $clientId = $this->clientId($provider);
        $secret = (string) ($provider->getCredentials()['client_secret'] ?? '');

        if ($clientId === '' || $secret === '') {
            throw new SocialProviderException(
                'MISCONFIGURED',
                'The Google provider needs a client_id setting and a client_secret credential.'
            );
        }

        try {
            $response = ProviderHttp::client()->asForm()->acceptJson()->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $exchange->code,
                'redirect_uri' => $exchange->redirectUri,
                'client_id' => $clientId,
                'client_secret' => $secret,
                'code_verifier' => $exchange->codeVerifier,
            ]);
        } catch (Throwable) {
            throw new SocialProviderException('TRANSPORT_ERROR', 'Google could not be reached to redeem the authorization code.');
        }

        if (! $response->successful()) {
            // Google's own error identifier, when it is one. Its description is not
            // copied: it is free text from a vendor and has no place in a record anyone
            // with integration access can read.
            $error = $response->json('error');
            $identifier = is_string($error) && preg_match('/^[a-z_]{1,64}$/', $error) === 1 ? $error : 'unrecognised';

            throw new SocialProviderException(
                'TOKEN_REJECTED',
                'Google refused the authorization code ('.$identifier.', HTTP '.$response->status().').'
            );
        }

        $idToken = $response->json('id_token');

        if (! is_string($idToken) || $idToken === '') {
            throw new SocialProviderException('ID_TOKEN_MISSING', 'Google answered without an ID token.');
        }

        $claims = $this->verifier->verify(
            $idToken,
            fn (string $keyId): ?string => $this->keys->publicKey($keyId),
            self::ISSUERS,
            $clientId,
            $exchange->nonce,
        );

        $email = isset($claims['email']) && is_string($claims['email']) && $claims['email'] !== ''
            ? $claims['email']
            : null;

        // Google sends a boolean; older tokens carried the string. Anything else is not an
        // assertion, and absence of an assertion is "not verified" (ADR 0050 §8).
        $asserted = $claims['email_verified'] ?? null;
        $verified = $asserted === true || $asserted === 'true';

        $name = isset($claims['name']) && is_string($claims['name']) && trim($claims['name']) !== ''
            ? trim($claims['name'])
            : null;

        return new SocialProfile((string) $claims['sub'], $email, $email !== null && $verified, $name);
    }

    private function clientId(IntegrationProvider $provider): string
    {
        $value = $provider->settings['client_id'] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
