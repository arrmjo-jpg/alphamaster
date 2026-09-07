<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers\Api;

use App\Modules\Auth\Exceptions\EmailVerificationThrottledException;
use App\Modules\Auth\Exceptions\InvalidVerificationLinkException;
use App\Modules\Auth\Services\EmailVerificationService;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\User\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends BaseApiController
{
    public function __construct(protected EmailVerificationService $verification) {}

    /**
     * Send a verification link to the authenticated account's address.
     *
     * Authenticated, and deliberately so: an endpoint that took an address and sent
     * mail to it would be an open relay for anyone who wanted to bother a stranger,
     * and answering it honestly would confirm which addresses hold accounts.
     */
    public function send(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $sent = $this->verification->send($user);
        } catch (EmailVerificationThrottledException $e) {
            return $this->errorResponse(
                'EMAIL_VERIFICATION_THROTTLED',
                $e->translationKey(),
                ['retry_after' => $e->retryAfterSeconds],
                429,
                $e->translationParameters()
            )->header('Retry-After', (string) $e->retryAfterSeconds);
        }

        // An already-verified address is answered rather than refused. The caller is
        // authenticated as itself, so there is nothing to protect here, and a client
        // that has just verified in another tab should be told the state rather than
        // handed an error to render.
        return $this->successResponse(
            ['email_verified' => ! $sent],
            $sent ? 'api.auth.email_verification_sent' : 'api.auth.email_already_verified'
        );
    }

    /**
     * Honour a signed verification link.
     *
     * Reached from an email client, so there is no bearer token and none is wanted:
     * the signature is the credential. `signed` middleware has already established
     * that this URL came from here and has not expired before anything below runs.
     *
     * A GET that changes state, which is the framework's own shape for this. It is
     * safe from the usual objection because the signature cannot be forged and the
     * operation is idempotent — repeating it, as a prefetching mail client will,
     * changes nothing.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        try {
            ['verified_now' => $verifiedNow] = $this->verification->verify($id, $hash);
        } catch (InvalidVerificationLinkException $e) {
            return $this->errorResponse(
                'INVALID_VERIFICATION_LINK',
                $e->translationKey(),
                null,
                403,
                $e->translationParameters()
            );
        }

        return $this->successResponse(
            ['email_verified' => true],
            $verifiedNow ? 'api.auth.email_verified' : 'api.auth.email_already_verified'
        );
    }
}
