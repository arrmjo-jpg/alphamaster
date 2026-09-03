<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers\Api;

use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Exceptions\AccountInactiveException;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Exceptions\MfaChallengeException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\MfaChallengeRequest;
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

            return $this->errorResponse('INVALID_CREDENTIALS', $e->getMessage(), [
                'attempts_remaining' => $this->throttle->remaining($key),
            ], 401);
        } catch (AccountInactiveException $e) {
            // A suspended account still counts as a failed attempt, so the endpoint
            // cannot be used to probe which accounts exist but are merely disabled.
            $this->throttle->recordFailure($key);

            return $this->errorResponse('ACCOUNT_SUSPENDED', $e->getMessage(), null, 403);
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
            return $this->errorResponse('ACCOUNT_SUSPENDED', $e->getMessage(), null, 403);
        } catch (MfaChallengeException $e) {
            $this->throttle->recordFailure($key);

            return $this->errorResponse('MFA_CHALLENGE_FAILED', $e->getMessage(), [
                'attempts_remaining' => $this->throttle->remaining($key),
            ], 401);
        }

        $this->throttle->clear($key);

        return $this->successResponse($issued->toArray(), 'Authenticated successfully.');
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

        return $this->successResponse([
            'id' => $user?->id,
            'name' => $user?->name,
            'email' => $user?->email,
            'is_admin' => (bool) $user?->is_admin,
            'is_active' => (bool) $user?->is_active,
            'abilities' => $token instanceof PersonalAccessToken ? $token->abilities : [],
        ]);
    }

    /**
     * Standard 429 with a Retry-After header.
     */
    private function throttledResponse(TooManyAttemptsException $e): JsonResponse
    {
        return $this->errorResponse(
            'TOO_MANY_ATTEMPTS',
            $e->getMessage(),
            ['retry_after' => $e->retryAfterSeconds],
            429
        )->header('Retry-After', (string) $e->retryAfterSeconds);
    }
}
