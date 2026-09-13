<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers\Api;

use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Exceptions\AccountInactiveException;
use App\Modules\Auth\Exceptions\SelfServiceAuthException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Requests\PhoneSignInRequest;
use App\Modules\Auth\Requests\SendPhoneSignInCodeRequest;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\CaptchaGuard;
use App\Modules\Auth\Services\LoginThrottle;
use App\Modules\Auth\Services\PhoneSignInService;
use App\Modules\Auth\Support\AuthCookie;
use App\Modules\Auth\Support\LoginIdentifier;
use App\Modules\Core\Controllers\BaseApiController;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Signing in, or registering, with a phone number and a one-time code (ADR 0051 §1).
 */
class PhoneSignInController extends BaseApiController
{
    public function __construct(
        protected PhoneSignInService $phones,
        protected AuthServiceContract $auth,
        protected LoginThrottle $throttle,
        protected CaptchaGuard $captcha,
    ) {}

    /**
     * Ask for a sign-in code.
     *
     * Answered the same way whether or not a code was sent, so it says nothing about which numbers have accounts. Every request counts against the throttle.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{expires_in: int, resend_after: int}}')]
    #[Response(404, description: 'PHONE_SIGN_IN_UNAVAILABLE: phone sign-in is switched off.')]
    #[Response(422, description: 'CAPTCHA_FAILED or VALIDATION_ERROR.')]
    #[Response(429, description: 'TOO_MANY_ATTEMPTS, with Retry-After.')]
    public function code(SendPhoneSignInCodeRequest $request): JsonResponse
    {
        $phone = (string) $request->validated('phone');
        $key = $this->throttle->key($request, 'phone-code', LoginIdentifier::canonicalise($phone));

        try {
            $this->throttle->assertNotLimited($key);
        } catch (TooManyAttemptsException $e) {
            return $this->throttled($e);
        }

        // Every request counts, not only failures: each one can put a message on the
        // operator's bill.
        $this->throttle->recordFailure($key);

        if (! $this->captcha->passes($request)) {
            return $this->refusal(SelfServiceAuthException::captchaFailed());
        }

        try {
            $this->phones->sendCode($phone);
        } catch (SelfServiceAuthException $e) {
            return $this->refusal($e);
        }

        return $this->successResponse([
            'expires_in' => $this->phones->codeLifetimeSeconds(),
            'resend_after' => $this->phones->resendCooldownSeconds(),
        ], 'If this number can sign in, a code has been sent to it.');
    }

    /**
     * Sign in, or register, with a code.
     *
     * An existing user account signs in: a token, or an MFA challenge for an account with a second factor. A number with no account is registered when registration is open and a name is given; the answer is then `201`. Administrators never sign in this way.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{token?: string, token_type?: string, abilities?: list<string>, mfa_required?: bool, mfa_token?: string, expires_in?: int}}')]
    #[Response(201, description: 'The code registered a new user account. The body is the same as for 200.')]
    #[Response(403, description: 'ACCOUNT_SUSPENDED or REGISTRATION_CLOSED.')]
    #[Response(404, description: 'PHONE_SIGN_IN_UNAVAILABLE.')]
    #[Response(422, description: 'PHONE_SIGN_IN_INVALID_CODE (no live code, a wrong one, or a number that may not sign in), REGISTRATION_DETAILS_REQUIRED (the code is right, the number has no account, and no name was given; the code is not spent) or VALIDATION_ERROR.')]
    #[Response(429, description: 'TOO_MANY_ATTEMPTS, with Retry-After.')]
    public function signIn(PhoneSignInRequest $request): JsonResponse
    {
        $phone = (string) $request->validated('phone');
        $key = $this->throttle->key($request, 'phone-sign-in', LoginIdentifier::canonicalise($phone));

        try {
            $this->throttle->assertNotLimited($key);

            $result = $this->phones->verify(
                $phone,
                (string) $request->validated('code'),
                $request->validated('name'),
                $request->validated('preferred_locale'),
            );
        } catch (TooManyAttemptsException $e) {
            return $this->throttled($e);
        } catch (AccountInactiveException $e) {
            $this->throttle->recordFailure($key);

            return $this->errorResponse('ACCOUNT_SUSPENDED', $e->translationKey(), null, 403, $e->translationParameters());
        } catch (SelfServiceAuthException $e) {
            if ($e->apiCode === 'PHONE_SIGN_IN_INVALID_CODE') {
                $this->throttle->recordFailure($key);
            }

            return $this->refusal($e);
        }

        $this->throttle->clear($key);

        $status = $result->created ? 201 : 200;

        if ($this->auth->requiresMfa($result->user)) {
            return $this->successResponse([
                'mfa_required' => true,
                'mfa_token' => $this->auth->startMfaChallenge($result->user),
                'expires_in' => AuthService::MFA_CHALLENGE_TTL,
            ], 'Multi-factor authentication is required to complete sign-in.', $status);
        }

        $issued = $this->auth->issueToken($result->user, 'phone-sign-in');

        return $this->successResponse($issued->toArray(), 'Authenticated successfully.', $status)
            ->withCookie(AuthCookie::issue($issued->plainTextToken));
    }

    private function refusal(SelfServiceAuthException $e): JsonResponse
    {
        return $this->errorResponse($e->apiCode, $e->translationKey(), null, $e->status, $e->translationParameters());
    }

    private function throttled(TooManyAttemptsException $e): JsonResponse
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
