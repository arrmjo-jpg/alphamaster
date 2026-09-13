<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers\Api;

use App\Modules\Auth\Exceptions\PhoneVerificationException;
use App\Modules\Auth\Requests\VerifyPhoneRequest;
use App\Modules\Auth\Services\PhoneVerifier;
use App\Modules\Core\Controllers\BaseApiController;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Confirming the number on your own account.
 *
 * Not administrative and behind no permission, for the same reason email verification
 * is not: the caller is proving something about themselves, and there is no endpoint
 * that confirms somebody else's number. An administrator can *see* whether a number is
 * confirmed, and cannot confirm one — the whole value of the state is that a person
 * answered a message.
 *
 * Both routes act on `$request->user()` and take no account identifier, which is what
 * keeps that true rather than a rule somebody has to remember.
 */
class PhoneVerificationController extends BaseApiController
{
    public function __construct(
        protected PhoneVerifier $verifier
    ) {}

    /**
     * Send a code to the number on this account.
     *
     * Throttled by a cooldown the platform is configured with, because every send
     * costs a real message: without one, anyone holding a session can spend an
     * operator's SMS budget, and a client retrying on a spinner does it by accident.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{destination: string}}')]
    public function send(Request $request): JsonResponse
    {
        try {
            $destination = $this->verifier->send($request->user());
        } catch (PhoneVerificationException $e) {
            return $this->refusal($e);
        }

        return $this->successResponse(
            // Masked. Enough for a client to say where the code went, not enough to be
            // a way of reading somebody's number back out of the platform.
            ['destination' => $destination],
            'api.auth.phone_verification_sent',
            replace: ['destination' => $destination]
        );
    }

    /**
     * Answer the outstanding code.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{phone_verified: bool, phone_verified_at: string}}')]
    public function verify(VerifyPhoneRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            $this->verifier->verify($user, (string) $request->validated('code'));
        } catch (PhoneVerificationException $e) {
            return $this->refusal($e);
        }

        return $this->successResponse(
            [
                'phone_verified' => true,
                'phone_verified_at' => $user->refresh()->phone_verified_at?->toIso8601String(),
            ],
            'api.auth.phone_verified'
        );
    }

    /**
     * A refusal, in the shape every other throttled endpoint here answers with.
     *
     * A 429 carries the wait in `details.retry_after` and in the header, the way login
     * and email verification do, so a client can run a countdown against the server's
     * own number instead of inventing one.
     */
    private function refusal(PhoneVerificationException $e): JsonResponse
    {
        $seconds = $e->translationParameters()['seconds'] ?? null;

        $response = $this->errorResponse(
            $this->codeFor($e),
            $e->translationKey(),
            $seconds === null ? null : ['retry_after' => (int) $seconds],
            $e->status,
            $e->translationParameters()
        );

        return $seconds === null
            ? $response
            : $response->header('Retry-After', (string) (int) $seconds);
    }

    /**
     * The stable identifier for a refusal.
     *
     * Derived from the message key rather than carried on the exception, so a code and
     * the sentence that explains it cannot describe two different things (ADR 0031).
     */
    private function codeFor(PhoneVerificationException $e): string
    {
        return match ($e->translationKey()) {
            'api.error.auth.phone_verification_no_number' => 'PHONE_NUMBER_MISSING',
            'api.error.auth.phone_already_verified' => 'PHONE_ALREADY_VERIFIED',
            'api.error.auth.phone_verification_throttled' => 'PHONE_VERIFICATION_THROTTLED',
            default => 'PHONE_VERIFICATION_INVALID_CODE',
        };
    }
}
