<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers\Api;

use App\Modules\Auth\Contracts\MfaManagerContract;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Exceptions\MfaEnrolmentException;
use App\Modules\Auth\Requests\MfaCodeRequest;
use App\Modules\Auth\Services\MfaManager;
use App\Modules\Core\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MfaController extends BaseApiController
{
    public function __construct(
        protected MfaManagerContract $mfa
    ) {}

    /**
     * Current MFA state for the authenticated user.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $enabled = $this->mfa->isEnabled($user);

        return $this->successResponse([
            'enabled' => $enabled,
            'recovery_codes_remaining' => $enabled && $this->mfa instanceof MfaManager
                ? $this->mfa->remainingRecoveryCodes($user)
                : 0,
        ]);
    }

    /**
     * Begin TOTP enrolment.
     *
     * The secret and provisioning URI are returned exactly once, here. The method
     * stays inactive until it is confirmed with a valid code.
     */
    public function enrol(Request $request): JsonResponse
    {
        try {
            $enrolment = $this->mfa->enrol($request->user(), MfaType::TOTP);
        } catch (MfaEnrolmentException $e) {
            return $this->errorResponse('MFA_ENROLMENT_INVALID', $e->getMessage(), null, 422);
        }

        return $this->successResponse([
            'type' => MfaType::TOTP->value,
            'secret' => $enrolment['secret'],
            'uri' => $enrolment['uri'],
        ], 'Scan the URI with an authenticator app, then confirm with a generated code.');
    }

    /**
     * Confirm the pending enrolment and activate MFA.
     *
     * Returns the recovery codes in plaintext once; only hashes are retained.
     */
    public function verify(MfaCodeRequest $request): JsonResponse
    {
        try {
            $recoveryCodes = $this->mfa->confirm(
                $request->user(),
                MfaType::TOTP,
                (string) $request->validated('code')
            );
        } catch (MfaEnrolmentException $e) {
            return $this->errorResponse('MFA_ENROLMENT_INVALID', $e->getMessage(), null, 422);
        }

        return $this->successResponse([
            'enabled' => true,
            'recovery_codes' => $recoveryCodes,
        ], 'Multi-factor authentication is now enabled. Store these recovery codes; they will not be shown again.');
    }

    /**
     * Disable MFA entirely.
     *
     * Requires a currently valid code or an unused recovery code, so a hijacked
     * session cannot strip the second factor off an account.
     */
    public function disable(MfaCodeRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->mfa->isEnabled($user)) {
            return $this->errorResponse(
                'MFA_NOT_ENABLED',
                'Multi-factor authentication is not enabled for this account.',
                null,
                422
            );
        }

        if (! $this->mfa->verifyChallenge($user, (string) $request->validated('code'))) {
            return $this->errorResponse(
                'MFA_CHALLENGE_FAILED',
                'The provided multi-factor code is not valid.',
                null,
                401
            );
        }

        $this->mfa->disable($user);

        return $this->successResponse(['enabled' => false], 'Multi-factor authentication has been disabled.');
    }
}
