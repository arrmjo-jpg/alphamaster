<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers\Api;

use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Contracts\MfaManagerContract;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Exceptions\AccountInactiveException;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Exceptions\MfaChallengeException;
use App\Modules\Auth\Exceptions\MfaDeliveryException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\MfaChallengeRequest;
use App\Modules\Auth\Requests\MfaChallengeSendRequest;
use App\Modules\Auth\Resources\AuthenticatedUserResource;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\LoginThrottle;
use App\Modules\Core\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends BaseApiController
{
    public function __construct(
        protected AuthServiceContract $auth,
        protected LoginThrottle $throttle,
        protected MfaManagerContract $mfa,
    ) {}

    /**
     * Authenticate and either issue a token or open an MFA challenge.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');
        $key = $this->throttle->key($request, 'login', $email);

        try {
            $this->throttle->assertNotLimited($key);

            $user = $this->auth->authenticate($email, (string) $request->validated('password'));
        } catch (TooManyAttemptsException $e) {
            return $this->throttledResponse($e);
        } catch (InvalidCredentialsException $e) {
            $this->throttle->recordFailure($key);

            return $this->errorResponse('INVALID_CREDENTIALS', $e->translationKey(), [
                'attempts_remaining' => $this->throttle->remaining($key),
            ], 401, $e->translationParameters());
        } catch (AccountInactiveException $e) {
            // A suspended account still counts as a failed attempt, so the endpoint
            // cannot be used to probe which accounts exist but are merely disabled.
            $this->throttle->recordFailure($key);

            return $this->errorResponse('ACCOUNT_SUSPENDED', $e->translationKey(), null, 403, $e->translationParameters());
        }

        $this->throttle->clear($key);

        // MFA is mandatory for administrators. One who has not enrolled receives no
        // access token, only a credential scoped to enrolment, so there is no window
        // in which an administrator holds access without a second factor.
        if ($this->auth->requiresMfaEnrolment($user)) {
            return $this->successResponse([
                'mfa_setup_required' => true,
                'enrolment_token' => $this->auth->issueEnrolmentToken($user)->plainTextToken,
                'token_type' => 'Bearer',
                'abilities' => [TokenAbility::MFA_ENROL->value],
            ], 'Multi-factor authentication is required for administrators. Enrol a second factor to continue.');
        }

        // A user with MFA enabled gets no access token here — only a short-lived
        // challenge token, which grants nothing on its own.
        if ($this->auth->requiresMfa($user)) {
            return $this->successResponse([
                'mfa_required' => true,
                'mfa_token' => $this->auth->startMfaChallenge($user),
                'expires_in' => AuthService::MFA_CHALLENGE_TTL,
            ], 'Multi-factor authentication is required to complete sign-in.');
        }

        return $this->successResponse(
            $this->auth->issueToken($user)->toArray(),
            'Authenticated successfully.'
        );
    }

    /**
     * Complete an MFA challenge and receive the access token.
     */
    public function mfaChallenge(MfaChallengeRequest $request): JsonResponse
    {
        $mfaToken = (string) $request->validated('mfa_token');
        $key = $this->throttle->key($request, 'mfa', $mfaToken);

        try {
            $this->throttle->assertNotLimited($key);

            $issued = $this->auth->completeMfaChallenge(
                $mfaToken,
                (string) $request->validated('code')
            );
        } catch (TooManyAttemptsException $e) {
            return $this->throttledResponse($e);
        } catch (AccountInactiveException $e) {
            return $this->errorResponse('ACCOUNT_SUSPENDED', $e->translationKey(), null, 403, $e->translationParameters());
        } catch (MfaChallengeException $e) {
            $this->throttle->recordFailure($key);

            return $this->errorResponse('MFA_CHALLENGE_FAILED', $e->translationKey(), [
                'attempts_remaining' => $this->throttle->remaining($key),
            ], 401, $e->translationParameters());
        }

        $this->throttle->clear($key);

        return $this->successResponse($issued->toArray(), 'Authenticated successfully.');
    }

    /**
     * Dispatch a code for a challenge that needs one.
     *
     * Delivery is a deliberate request rather than a side effect of signing in: an
     * attacker holding a password must not be able to make the platform send
     * unlimited messages to the account owner's phone. The endpoint is throttled on
     * the challenge token, and the method itself enforces a resend cooldown.
     */
    public function mfaChallengeSend(MfaChallengeSendRequest $request): JsonResponse
    {
        $mfaToken = (string) $request->validated('mfa_token');
        $key = $this->throttle->key($request, 'mfa-send', $mfaToken);

        try {
            $this->throttle->assertNotLimited($key);

            $user = $this->auth->resolveMfaChallenge($mfaToken);
            $destination = $this->mfa->deliverChallenge($user);
        } catch (TooManyAttemptsException $e) {
            return $this->throttledResponse($e);
        } catch (AccountInactiveException $e) {
            return $this->errorResponse('ACCOUNT_SUSPENDED', $e->translationKey(), null, 403, $e->translationParameters());
        } catch (MfaChallengeException $e) {
            $this->throttle->recordFailure($key);

            return $this->errorResponse('MFA_CHALLENGE_FAILED', $e->translationKey(), null, 401, $e->translationParameters());
        } catch (MfaDeliveryException $e) {
            return $this->errorResponse('MFA_DELIVERY_THROTTLED', $e->translationKey(), null, 429, $e->translationParameters());
        }

        $this->throttle->recordFailure($key);

        if ($destination === null) {
            return $this->errorResponse(
                'MFA_DELIVERY_NOT_APPLICABLE',
                'api.error.auth.mfa_delivery_not_applicable',
                null,
                422
            );
        }

        return $this->successResponse(
            ['destination' => $destination],
            'A verification code has been sent.'
        );
    }

    /**
     * Revoke the token used to make this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return $this->successResponse(null, 'Signed out successfully.');
    }

    /**
     * The authenticated identity behind the presented token.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        return $this->successResponse(new AuthenticatedUserResource(
            $user,
            $token instanceof PersonalAccessToken ? $token->abilities : [],
        ));
    }

    /**
     * Standard 429 with a Retry-After header.
     */
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
