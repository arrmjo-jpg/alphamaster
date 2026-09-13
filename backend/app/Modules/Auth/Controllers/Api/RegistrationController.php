<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers\Api;

use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Exceptions\SelfServiceAuthException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Requests\RegisterRequest;
use App\Modules\Auth\Services\CaptchaGuard;
use App\Modules\Auth\Services\LoginThrottle;
use App\Modules\Auth\Services\RegistrationService;
use App\Modules\Auth\Support\AuthCookie;
use App\Modules\Core\Controllers\BaseApiController;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Public registration with an email address and a password (ADR 0051 §3).
 */
class RegistrationController extends BaseApiController
{
    public function __construct(
        protected RegistrationService $registration,
        protected AuthServiceContract $auth,
        protected LoginThrottle $throttle,
        protected CaptchaGuard $captcha,
    ) {}

    /**
     * Open a user account.
     *
     * Always a user account. A verification link is sent to the address; the account may be used before it is followed.
     */
    #[Response(201, type: 'array{success: bool, message: string, data: array{token: string, token_type: string, abilities: list<string>, email_verification_sent: bool}}')]
    #[Response(403, description: 'REGISTRATION_CLOSED.')]
    #[Response(422, description: 'CAPTCHA_FAILED or VALIDATION_ERROR (including an address or number already in use).')]
    #[Response(429, description: 'TOO_MANY_ATTEMPTS, with Retry-After.')]
    public function register(RegisterRequest $request): JsonResponse
    {
        // Keyed on the address the request comes from, not the email it names: a caller
        // creating accounts varies the email every time.
        $key = $this->throttle->key($request, 'register', 'any');

        try {
            $this->throttle->assertNotLimited($key);
        } catch (TooManyAttemptsException $e) {
            return $this->errorResponse(
                'TOO_MANY_ATTEMPTS',
                $e->translationKey(),
                ['retry_after' => $e->retryAfterSeconds],
                429,
                $e->translationParameters()
            )->header('Retry-After', (string) $e->retryAfterSeconds);
        }

        // Every request counts: each one can create an account and send a message.
        $this->throttle->recordFailure($key);

        if (! $this->captcha->passes($request)) {
            $refusal = SelfServiceAuthException::captchaFailed();

            return $this->errorResponse($refusal->apiCode, $refusal->translationKey(), null, $refusal->status);
        }

        try {
            $registered = $this->registration->register(
                (string) $request->validated('name'),
                (string) $request->validated('email'),
                (string) $request->validated('password'),
                $request->validated('phone'),
                $request->validated('preferred_locale'),
            );
        } catch (SelfServiceAuthException $e) {
            return $this->errorResponse($e->apiCode, $e->translationKey(), null, $e->status, $e->translationParameters());
        }

        $issued = $this->auth->issueToken($registered['user'], 'registration');

        return $this->successResponse(
            $issued->toArray() + ['email_verification_sent' => $registered['verification_sent']],
            'Account created. A link to verify your email address has been sent.',
            201
        )->withCookie(AuthCookie::issue($issued->plainTextToken));
    }
}
