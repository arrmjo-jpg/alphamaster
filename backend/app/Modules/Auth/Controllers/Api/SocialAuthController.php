<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers\Api;

use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Exceptions\SocialLoginException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Requests\SocialAuthorizeRequest;
use App\Modules\Auth\Requests\SocialCallbackRequest;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\LoginThrottle;
use App\Modules\Auth\Services\SocialLoginFlow;
use App\Modules\Auth\Support\AuthCookie;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\User\Contracts\SocialIdentityRegistryContract;
use App\Modules\User\Exceptions\SocialIdentityException;
use App\Modules\User\Models\SocialIdentity;
use App\Modules\User\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

/**
 * Social login for user accounts (ADR 0050).
 *
 * The API performs the code exchange and the client only carries the redirect, so the
 * same contract serves a browser and a native app and assumes no frontend domain.
 */
class SocialAuthController extends BaseApiController
{
    public function __construct(
        protected SocialLoginFlow $flow,
        protected AuthServiceContract $auth,
        protected LoginThrottle $throttle,
        protected SocialIdentityRegistryContract $identities,
    ) {}

    /**
     * The social providers a client may offer.
     *
     * Only providers that are switched on and fully configured. Empty while social login is off.
     */
    #[Response(200, type: 'array{success: bool, data: list<array{key: string, label: string}>}')]
    public function providers(): JsonResponse
    {
        return $this->successResponse($this->flow->providers());
    }

    /**
     * Begin signing in with a provider.
     *
     * Send the person to `authorization_url`. The provider returns them to the redirect URI with a code and a state, which the client posts to the callback together with its PKCE verifier.
     */
    #[Response(200, type: 'array{success: bool, data: array{authorization_url: string, expires_at: string}}')]
    #[Response(404, description: 'SOCIAL_PROVIDER_UNAVAILABLE: social sign-in is off, or the provider is unknown, switched off or not fully configured.')]
    #[Response(422, description: 'INVALID_REDIRECT_URI: the redirect URI is not an exact entry of auth.social_redirect_uris usable in this environment; or VALIDATION_ERROR.')]
    public function authorize(SocialAuthorizeRequest $request, string $provider): JsonResponse
    {
        try {
            $authorization = $this->flow->authorize(
                $provider,
                (string) $request->validated('redirect_uri'),
                (string) $request->validated('code_challenge'),
                SocialLoginFlow::INTENT_SIGN_IN,
            );
        } catch (SocialLoginException $e) {
            return $this->refusal($e);
        }

        return $this->successResponse($authorization);
    }

    /**
     * Finish signing in with a provider.
     *
     * Answers the way password sign-in does: an access token, or an MFA challenge for an account with a second factor. `201` when the sign-in created the account.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{token?: string, token_type?: string, abilities?: list<string>, mfa_required?: bool, mfa_token?: string, expires_in?: int}}')]
    #[Response(201, description: 'The sign-in created a user account. The body is the same as for 200.')]
    #[Response(403, description: 'SOCIAL_SIGN_IN_REFUSED (administrator account or address, disabled account, unlinked identity, address the provider does not vouch for) or REGISTRATION_CLOSED.')]
    #[Response(404, description: 'SOCIAL_PROVIDER_UNAVAILABLE.')]
    #[Response(409, description: 'SOCIAL_IDENTITY_NOT_LINKED: the verified address belongs to an existing user, who signs in and links explicitly.')]
    #[Response(422, description: 'SOCIAL_STATE_INVALID (unknown, expired, already used, or bound to another provider, intent or PKCE verifier) or VALIDATION_ERROR.')]
    #[Response(429, description: 'TOO_MANY_ATTEMPTS, with Retry-After.')]
    #[Response(502, description: 'SOCIAL_PROVIDER_ERROR: the code exchange or ID token validation failed. The detail is recorded in the integration usage log only.')]
    public function callback(SocialCallbackRequest $request, string $provider): JsonResponse
    {
        $key = $this->throttle->key($request, 'social', $provider);

        try {
            $this->throttle->assertNotLimited($key);

            $signIn = $this->flow->signIn(
                $provider,
                (string) $request->validated('code'),
                (string) $request->validated('state'),
                (string) $request->validated('code_verifier'),
            );
        } catch (TooManyAttemptsException $e) {
            return $this->throttledResponse($e);
        } catch (SocialLoginException $e) {
            $this->throttle->recordFailure($key);

            return $this->refusal($e);
        }

        $this->throttle->clear($key);

        return $this->signedIn($signIn->user, $signIn->created ? 201 : 200);
    }

    /**
     * The signed-in account's linked social identities.
     */
    #[Response(200, type: 'array{success: bool, data: list<array{id: string, provider: string, linked_at: string, last_used_at: string|null}>}')]
    public function identities(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(
            $this->identities->linkedIdentities($user)
                ->map(static fn (SocialIdentity $identity): array => [
                    'id' => $identity->id,
                    'provider' => $identity->provider,
                    'linked_at' => $identity->linked_at->toIso8601String(),
                    'last_used_at' => $identity->last_used_at?->toIso8601String(),
                ])
                ->values()
                ->all()
        );
    }

    /**
     * Begin linking a provider to the signed-in account.
     */
    #[Response(200, type: 'array{success: bool, data: array{authorization_url: string, expires_at: string}}')]
    #[Response(403, description: 'SOCIAL_SIGN_IN_REFUSED: administrators never link a social identity; or an administrator token.')]
    #[Response(404, description: 'SOCIAL_PROVIDER_UNAVAILABLE.')]
    #[Response(422, description: 'INVALID_REDIRECT_URI or VALIDATION_ERROR.')]
    public function linkAuthorize(SocialAuthorizeRequest $request, string $provider): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $authorization = $this->flow->authorize(
                $provider,
                (string) $request->validated('redirect_uri'),
                (string) $request->validated('code_challenge'),
                SocialLoginFlow::INTENT_LINK,
                $user,
            );
        } catch (SocialLoginException $e) {
            return $this->refusal($e);
        }

        return $this->successResponse($authorization);
    }

    /**
     * Finish linking a provider to the signed-in account.
     */
    #[Response(201, type: 'array{success: bool, message: string, data: array{id: string, provider: string, linked_at: string}}')]
    #[Response(403, description: 'SOCIAL_SIGN_IN_REFUSED: administrators never link a social identity; or an administrator token.')]
    #[Response(404, description: 'SOCIAL_PROVIDER_UNAVAILABLE.')]
    #[Response(409, description: 'SOCIAL_IDENTITY_IN_USE (the identity belongs to another account, linked or not) or SOCIAL_PROVIDER_ALREADY_LINKED.')]
    #[Response(422, description: 'SOCIAL_STATE_INVALID (including a state issued for signing in or to another account) or VALIDATION_ERROR.')]
    #[Response(429, description: 'TOO_MANY_ATTEMPTS, with Retry-After.')]
    #[Response(502, description: 'SOCIAL_PROVIDER_ERROR.')]
    public function link(SocialCallbackRequest $request, string $provider): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $key = $this->throttle->key($request, 'social-link', $provider);

        try {
            $this->throttle->assertNotLimited($key);

            $link = $this->flow->link(
                $user,
                $provider,
                (string) $request->validated('code'),
                (string) $request->validated('state'),
                (string) $request->validated('code_verifier'),
            );
        } catch (TooManyAttemptsException $e) {
            return $this->throttledResponse($e);
        } catch (SocialLoginException $e) {
            $this->throttle->recordFailure($key);

            return $this->refusal($e);
        } catch (SocialIdentityException $e) {
            $this->throttle->recordFailure($key);

            return $this->identityRefusal($e);
        }

        $this->throttle->clear($key);

        return $this->successResponse([
            'id' => $link->identity->id,
            'provider' => $link->identity->provider,
            'linked_at' => $link->identity->linked_at->toIso8601String(),
        ], 'Social identity linked.', 201);
    }

    /**
     * Unlink one of the signed-in account's identities.
     *
     * The identity is kept as history and its subject stays reserved to this account. Refused when it would leave the account with no way to sign in.
     */
    #[Response(204, description: 'Unlinked. The row is kept and its subject stays reserved to this account.')]
    #[Response(404, description: 'NOT_FOUND: no linked identity with that id belongs to this account.')]
    #[Response(409, description: 'LAST_SIGN_IN_METHOD: the account has no password and no other linked identity.')]
    public function unlink(Request $request, string $identity): JsonResponse|HttpResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->identities->unlink($user, $identity);
        } catch (SocialIdentityException $e) {
            return $this->identityRefusal($e);
        }

        return response()->noContent();
    }

    /**
     * A token, or an MFA challenge — the two outcomes a user account's sign-in has.
     *
     * The administrative outcomes password sign-in also has — verification and mandatory
     * enrolment — do not exist here, because no administrator reaches this point.
     */
    private function signedIn(User $user, int $status): JsonResponse
    {
        if ($this->auth->requiresMfa($user)) {
            return $this->successResponse([
                'mfa_required' => true,
                'mfa_token' => $this->auth->startMfaChallenge($user),
                'expires_in' => AuthService::MFA_CHALLENGE_TTL,
            ], 'Multi-factor authentication is required to complete sign-in.', $status);
        }

        $issued = $this->auth->issueSocialToken($user);

        return $this->successResponse($issued->toArray(), 'Authenticated successfully.', $status)
            ->withCookie(AuthCookie::issue($issued->plainTextToken));
    }

    private function refusal(SocialLoginException $e): JsonResponse
    {
        return $this->errorResponse($e->apiCode, $e->translationKey(), null, $e->status, $e->translationParameters());
    }

    private function identityRefusal(SocialIdentityException $e): JsonResponse
    {
        [$code, $status] = match ($e->reason) {
            SocialIdentityException::ADMINISTRATOR => ['SOCIAL_SIGN_IN_REFUSED', 403],
            SocialIdentityException::IDENTITY_IN_USE => ['SOCIAL_IDENTITY_IN_USE', 409],
            SocialIdentityException::PROVIDER_ALREADY_LINKED => ['SOCIAL_PROVIDER_ALREADY_LINKED', 409],
            SocialIdentityException::LAST_SIGN_IN_METHOD => ['LAST_SIGN_IN_METHOD', 409],
            default => ['NOT_FOUND', 404],
        };

        return $this->errorResponse($code, $e->translationKey(), null, $status, $e->translationParameters());
    }

    private function throttledResponse(TooManyAttemptsException $e): JsonResponse
    {
        return $this->errorResponse(
            'TOO_MANY_ATTEMPTS',
            $e->translationKey(),
            ['retry_after' => $e->retryAfterSeconds],
            429,
            $e->translationParameters()
        )->header('Retry-After', (string) $e->retryAfterSeconds);
    }
}
