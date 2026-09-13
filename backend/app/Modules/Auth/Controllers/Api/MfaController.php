<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers\Api;

use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Contracts\MfaManagerContract;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Exceptions\MfaEnrolmentException;
use App\Modules\Auth\Requests\MfaCodeRequest;
use App\Modules\Auth\Requests\MfaEnrolRequest;
use App\Modules\Auth\Services\MfaManager;
use App\Modules\Auth\Support\AuthCookie;
use App\Modules\Core\Contracts\PlatformNotifierContract;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Translation\Phrase;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;

class MfaController extends BaseApiController
{
    public function __construct(
        protected MfaManagerContract $mfa,
        protected AuthServiceContract $auth,
        protected PlatformNotifierContract $notifier,
    ) {}

    /**
     * Current MFA state for the authenticated user.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $enabled = $this->mfa->isEnabled($user);

        $confirmed = array_values(array_filter(
            MfaType::values(),
            fn (string $type): bool => $this->mfa->hasConfirmedMethod($user, MfaType::from($type))
        ));

        $available = $this->availableMethods($request);

        return $this->successResponse([
            'enabled' => $enabled,
            'satisfies_policy' => $this->mfa->satisfiesPolicy($user),
            'methods' => $confirmed,
            'methods_options' => $this->methodOptions($confirmed),
            'available_methods' => $available,
            'available_methods_options' => $this->methodOptions($available),
            'recovery_codes_remaining' => $enabled && $this->mfa instanceof MfaManager
                ? $this->mfa->remainingRecoveryCodes($user)
                : 0,
        ]);
    }

    /**
     * Begin enrolment for a method.
     *
     * TOTP returns a secret and a provisioning URI; SMS returns the masked number a
     * first code has just been sent to. The response carries only what the chosen
     * method actually has.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{type: string, secret?: string, uri?: string, destination?: string}}')]
    public function enrol(MfaEnrolRequest $request): JsonResponse
    {
        $type = MfaType::from((string) ($request->validated('type') ?? MfaType::TOTP->value));

        try {
            $enrolment = $this->mfa->enrol($request->user(), $type, [
                'phone' => $request->validated('phone'),
            ]);
        } catch (MfaEnrolmentException $e) {
            return $this->errorResponse('MFA_ENROLMENT_INVALID', $e->translationKey(), null, 422, $e->translationParameters());
        }

        return $this->successResponse(
            $enrolment->toArray(),
            $type->requiresDelivery()
                ? 'A verification code has been sent. Enter it to confirm this method.'
                : 'Scan the URI with an authenticator app, then confirm with a generated code.'
        );
    }

    /**
     * Confirm the pending enrolment and activate the method.
     *
     * Returns the recovery codes in plaintext once; only hashes are retained.
     */
    public function verify(MfaCodeRequest $request): JsonResponse
    {
        $type = MfaType::tryFrom((string) $request->input('type', MfaType::TOTP->value)) ?? MfaType::TOTP;

        try {
            $recoveryCodes = $this->mfa->confirm(
                $request->user(),
                $type,
                (string) $request->validated('code')
            );
        } catch (MfaEnrolmentException $e) {
            return $this->errorResponse('MFA_ENROLMENT_INVALID', $e->translationKey(), null, 422, $e->translationParameters());
        }

        // A second factor now guards this account. Somebody who did not add it needs
        // to hear about it, because from here on it decides who can sign in — so the
        // account is told on every channel it has, and cannot opt out (ADR 0019).
        $this->notifier->notify($request->user(), 'security.alert', [
            'event' => new Phrase('notifications.event.mfa_method_added'),
        ]);

        $payload = [
            'enabled' => true,
            'recovery_codes' => $recoveryCodes,
        ];

        // Typed as Sanctum's own contract rather than the concrete token model.
        // HasApiTokens declares `@template TToken of HasAbilities = PersonalAccessToken`,
        // so static analysis reads currentAccessToken() as always being a
        // PersonalAccessToken and calls the check below redundant. At runtime it is
        // not: config/sanctum.php sets `guard => ['web']`, and Sanctum's Guard checks
        // that guard first, attaching a TransientToken to a session-authenticated
        // user — and TransientToken::can() returns true for every ability without
        // looking at it.
        //
        // So the check is load-bearing. Removing it would read that unconditional
        // true as proof of an enrolment credential and mint a full access token for a
        // session that never held one, then call delete() on a token that has no such
        // method.
        /** @var HasAbilities|null $current */
        $current = $request->user()->currentAccessToken();

        // An administrator who arrived on an enrolment token has now proved possession
        // of the factor, which together with the password they signed in with is a
        // complete two-factor authentication. Hand them the real token and burn the
        // enrolment credential, rather than making them sign in twice.
        $exchanged = null;

        if ($current instanceof PersonalAccessToken && $current->can(TokenAbility::MFA_ENROL->value)) {
            $current->delete();
            $exchanged = $this->auth->issueToken($request->user());
            $payload = array_merge($payload, $exchanged->toArray());
        }

        $response = $this->successResponse(
            $payload,
            'Multi-factor authentication is now enabled. Store these recovery codes; they will not be shown again.'
        );

        // The exchange replaces one credential with another, so the cookie has to
        // follow (ADR 0042). Without this the browser would keep presenting the
        // enrolment cookie for a token that was just deleted, and the next request
        // would be unauthenticated for reasons nothing in the response explained.
        //
        // Only when an exchange actually happened: a regular user enrolling
        // voluntarily arrives on their own access token, which is still valid and must
        // not be overwritten.
        return $exchanged === null
            ? $response
            : $response->withCookie(AuthCookie::issue($exchanged->plainTextToken));
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
                'api.error.auth.mfa_not_enabled',
                null,
                422
            );
        }

        if (! $this->mfa->verifyChallenge($user, (string) $request->validated('code'))) {
            return $this->errorResponse(
                'MFA_CHALLENGE_FAILED',
                'api.error.auth.mfa_code_invalid',
                null,
                401
            );
        }

        $this->mfa->disable($user);

        // The protection that just came off is the one that would have stopped a
        // stolen password. A code was required to get here, but a code can be read
        // off a phone left unlocked — the owner hears about it either way.
        $this->notifier->notify($user, 'security.alert', [
            'event' => new Phrase('notifications.event.mfa_disabled'),
        ]);

        // MFA is mandatory for administrators, so one who disables it must not keep
        // the access token they already hold. Revoking every token means the invariant
        // "an administrator holding access has a second factor" is true at all times,
        // and their next sign-in walks them back through enrolment.
        if ($user->isAdmin()) {
            $user->tokens()->delete();

            return $this->successResponse(
                ['enabled' => false, 'tokens_revoked' => true],
                'Multi-factor authentication has been disabled. All sessions were signed out, and enrolment is required at next sign-in.'
            );
        }

        return $this->successResponse(['enabled' => false], 'Multi-factor authentication has been disabled.');
    }

    /**
     * The methods this caller may enrol.
     *
     * An administrator is shown only the methods that satisfy the mandatory policy, so
     * a client does not offer a choice the server will refuse.
     *
     * @return array<int, string>
     */
    private function availableMethods(Request $request): array
    {
        $isAdmin = $request->user()->isAdmin();

        return array_values(array_map(
            static fn (MfaType $type): string => $type->value,
            array_filter(
                MfaType::cases(),
                static fn (MfaType $type): bool => ! $isAdmin || $type->satisfiesAdministratorPolicy()
            )
        ));
    }

    /**
     * The catalogue for a list of method values that has already been filtered.
     *
     * Built from the list it accompanies rather than from `MfaType::options()`,
     * which returns every case: an administrator's payload would then offer
     * `sms_otp`, the one factor ADR 0013 refuses them. Order and membership come
     * from the source array, so the catalogue cannot drift from the field it
     * describes (ADR 0031).
     *
     * @param  array<int, string>  $values
     * @return array<int, array{value: string, label: string}>
     */
    private function methodOptions(array $values): array
    {
        return array_map(
            static fn (string $value): array => [
                'value' => $value,
                'label' => MfaType::from($value)->label(),
            ],
            $values
        );
    }
}
