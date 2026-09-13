<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers\Api;

use App\Modules\Auth\Exceptions\PasswordResetException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Requests\ResetPasswordRequest;
use App\Modules\Auth\Services\LoginThrottle;
use App\Modules\Auth\Services\PasswordRecoveryService;
use App\Modules\Core\Controllers\BaseApiController;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Account recovery by email (ADR 0050 §12).
 */
class PasswordRecoveryController extends BaseApiController
{
    public function __construct(
        protected PasswordRecoveryService $recovery,
        protected LoginThrottle $throttle,
    ) {}

    /**
     * Ask for a password reset link.
     *
     * Answered the same way whether or not the address holds an account.
     */
    #[Response(429, description: 'TOO_MANY_ATTEMPTS: every request counts, because each can send a message.')]
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->validated('email')));
        $key = $this->throttle->key($request, 'password-forgot', $email);

        try {
            $this->throttle->assertNotLimited($key);
        } catch (TooManyAttemptsException $e) {
            return $this->throttledResponse($e);
        }

        // Every request counts, not only failures: each one can send a message to an inbox
        // that belongs to someone who did not ask.
        $this->throttle->recordFailure($key);

        $this->recovery->requestLink($email);

        return $this->successResponse(null, 'If that address belongs to an account, a link to reset its password has been sent.');
    }

    /**
     * Set a new password with a reset token. Every session the account had is signed out.
     */
    #[Response(422, description: 'PASSWORD_RESET_INVALID (the same answer for a wrong, expired or used token and for an address with no account) or VALIDATION_ERROR.')]
    #[Response(429, description: 'TOO_MANY_ATTEMPTS.')]
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->validated('email')));
        $key = $this->throttle->key($request, 'password-reset', $email);

        try {
            $this->throttle->assertNotLimited($key);

            $this->recovery->reset(
                $email,
                (string) $request->validated('token'),
                (string) $request->validated('password'),
            );
        } catch (TooManyAttemptsException $e) {
            return $this->throttledResponse($e);
        } catch (PasswordResetException $e) {
            $this->throttle->recordFailure($key);

            return $this->errorResponse('PASSWORD_RESET_INVALID', $e->translationKey(), null, 422, $e->translationParameters());
        }

        $this->throttle->clear($key);

        return $this->successResponse(null, 'Your password has been reset. Sign in with the new password.');
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
