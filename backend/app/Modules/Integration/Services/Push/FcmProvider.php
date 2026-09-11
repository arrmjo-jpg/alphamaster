<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Push;

use App\Modules\Integration\Contracts\PushProviderContract;
use App\Modules\Integration\Data\PushMessage;
use App\Modules\Integration\Data\PushResult;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Services\ProviderHttp;

/**
 * Firebase Cloud Messaging, over the HTTP client rather than the vendor SDK.
 *
 * Firebase is a push transport to this platform and nothing else (ADR 0045 §1). There
 * is no Firebase Auth here, no Firestore, no Firebase Storage: identity is ADR 0012's,
 * the database is ADR 0003's, and media is ADR 0024's. What is adopted is the one
 * thing Firebase does that would otherwise mean running an APNs relay.
 *
 * Two things about FCM v1 shape this driver.
 *
 * **The credential is a service account, not an API key.** Authentication is a signed
 * JWT exchanged for a short-lived OAuth token, so the driver holds a private key
 * rather than a bearer string. It is read from encrypted storage, used, and never
 * logged or returned.
 *
 * **The access token is cached.** Minting one costs a network round trip and a
 * signature; doing that per message would double the cost of every push. It is held in
 * the platform cache (ADR 0035) under a key derived from the provider row, and
 * deliberately expires before the vendor's own expiry so a token is never used in the
 * seconds around its edge.
 */
class FcmProvider implements PushProviderContract
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const SEND_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    /**
     * Google issues a one-hour token. Ours is held for fifty minutes, so a message is
     * never sent with a token that expires while it is in flight.
     */
    private const TOKEN_TTL_SECONDS = 3000;

    /**
     * The two answers that mean the address is dead rather than the request being bad.
     *
     * `UNREGISTERED` is the app being uninstalled or the token rotated;
     * `INVALID_ARGUMENT` on a send is FCM's answer for a malformed or foreign token.
     * Both are authoritative, and both mean the row should go (ADR 0045 §5).
     */
    private const DEAD_TOKEN_CODES = ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'];

    public function __construct(private readonly FcmAccessToken $tokens) {}

    public function driver(): string
    {
        return 'fcm';
    }

    public function send(PushMessage $message, IntegrationProvider $provider): PushResult
    {
        $credentials = $provider->getCredentials();
        $projectId = (string) ($credentials['project_id'] ?? '');

        if ($projectId === '' || ! isset($credentials['client_email'], $credentials['private_key'])) {
            return PushResult::failure(
                $this->driver(),
                'MISCONFIGURED',
                'The FCM provider needs a service-account credential with project_id, client_email and private_key.'
            );
        }

        $accessToken = $this->tokens->for($provider, $credentials, self::SCOPE, self::TOKEN_ENDPOINT, self::TOKEN_TTL_SECONDS);

        if ($accessToken === null) {
            return PushResult::failure(
                $this->driver(),
                'AUTH_FAILED',
                'The service account could not be exchanged for an access token.'
            );
        }

        try {
            $response = ProviderHttp::client()
                ->withToken($accessToken)
                ->post(sprintf(self::SEND_ENDPOINT, $projectId), [
                    'message' => [
                        'token' => $message->token,
                        // Data only. No `notification` block, so nothing the platform
                        // said is rendered by the operating system from the payload —
                        // the client decides what a lock screen shows, from the type.
                        'data' => $message->data(),
                    ],
                ]);
        } catch (\Throwable $e) {
            return PushResult::failure($this->driver(), 'TRANSPORT_ERROR', $e->getMessage());
        }

        if ($response->successful()) {
            return PushResult::success($this->driver(), (string) $response->json('name'));
        }

        $status = (string) ($response->json('error.status') ?? $response->status());
        $detail = (string) ($response->json('error.message') ?? 'The FCM request failed.');

        return in_array($status, self::DEAD_TOKEN_CODES, true)
            ? PushResult::tokenRejected($this->driver(), $status, $detail)
            : PushResult::failure($this->driver(), $status, $detail);
    }
}
