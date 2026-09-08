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
use App\Modules\Auth\Services\CaptchaGuard;
use App\Modules\Auth\Services\LoginThrottle;
use App\Modules\Auth\Support\AuthCookie;
use App\Modules\Auth\Support\LoginIdentifier;
use App\Modules\Core\Contracts\EffectiveGrants;
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
        protected EffectiveGrants $grants,
        protected CaptchaGuard $captcha,
    ) {}

    /**
     * Authenticate and either issue a token or open an MFA challenge.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = (string) $request->validated('identifier');

        // The throttle counts against the canonical identifier, not the typed one.
        // Otherwise `+962 79 000 0000` and `+962790000000` are two buckets for one
        // account, and the limiter is bypassed by varying the spacing.
        $key = $this->throttle->key($request, 'login', LoginIdentifier::canonicalise($identifier));

        try {
            $this->throttle->assertNotLimited($key);

            // Second, and before the credentials are read. The order is the point:
            // the limiter runs first so a captcha cannot be used to buy unlimited
            // attempts, and the captcha runs before authenticate() so an automated
            // attempt never reaches a password comparison at all.
            //
            // The refusal is raised as InvalidCredentialsException rather than
            // written out here, so it travels the existing path and produces the
            // byte-identical response a wrong password produces — including the
            // recorded failure and therefore the same attempts_remaining. Building a
            // second response here would be a second thing to keep in step, and the
            // first time they diverged the difference would tell an attacker which
            // check it had tripped.
            if (! $this->captcha->passes($request)) {
                throw new InvalidCredentialsException;
            }

            $user = $this->auth->authenticate($identifier, (string) $request->validated('password'));
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

        // Verification comes before enrolment, so an administrator settles one
        // prerequisite at a time and in the order that makes the second one worth
        // doing: enrolling a second factor against an address nobody has proved
        // control of secures an identity that is not yet established.
        //
        // The ordering is also what keeps the enrolment exchange honest. Completing
        // enrolment hands back a real admin:access token in the same response (ADR
        // 0013); if an unverified administrator could reach enrolment, that exchange
        // would be a path to administrative access without a verified address.
        if ($this->auth->requiresEmailVerification($user)) {
            $verification = $this->auth->issueEmailVerificationToken($user)->plainTextToken;

            return $this->successResponse([
                'email_verification_required' => true,
                'verification_token' => $verification,
                'token_type' => 'Bearer',
                'abilities' => [TokenAbility::EMAIL_VERIFY->value],
            ], 'Verify your email address to continue. Request a verification link to proceed.')
                ->withCookie(AuthCookie::issue($verification));
        }

        // MFA is mandatory for administrators. One who has not enrolled receives no
        // access token, only a credential scoped to enrolment, so there is no window
        // in which an administrator holds access without a second factor.
        if ($this->auth->requiresMfaEnrolment($user)) {
            $enrolment = $this->auth->issueEnrolmentToken($user)->plainTextToken;

            return $this->successResponse([
                'mfa_setup_required' => true,
                'enrolment_token' => $enrolment,
                'token_type' => 'Bearer',
                'abilities' => [TokenAbility::MFA_ENROL->value],
            ], 'Multi-factor authentication is required for administrators. Enrol a second factor to continue.')
                ->withCookie(AuthCookie::issue($enrolment));
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

        $issued = $this->auth->issueToken($user);

        return $this->successResponse($issued->toArray(), 'Authenticated successfully.')
            ->withCookie(AuthCookie::issue($issued->plainTextToken));
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

        return $this->successResponse($issued->toArray(), 'Authenticated successfully.')
            ->withCookie(AuthCookie::issue($issued->plainTextToken));
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
     * Revoke the token used to make this request and clear the authentication cookie.
     *
     * Only the presented credential is revoked, so signing out of one browser leaves
     * another signed in.
     */
    public function logout(Request $request): JsonResponse
    {
        // Both halves are necessary and neither is sufficient. Deleting the token
        // without clearing the cookie leaves the browser presenting a credential that
        // no longer resolves — every later request fails as 401 for a reason nothing
        // explains, and the client cannot clear it itself because the cookie is
        // HttpOnly. Clearing the cookie without deleting the token leaves a live
        // credential behind, which is the more serious half: a copy of it would still
        // work.
        //
        // The cookie is attached whether or not this request arrived by cookie. A
        // bearer caller has none to clear and is unaffected, and branching on the
        // transport would be a condition with no benefit.
        //
        // Written here rather than beside the return: Scramble publishes a docblock as
        // the operation description and the comment before a return as the response
        // description, and internal reasoning is not what an API consumer should be
        // handed.
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return $this->successResponse(null, 'Signed out successfully.')
            ->withCookie(AuthCookie::forget());
    }

    /**
     * The authenticated identity behind the presented token.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Asked through the Core contract rather than the Authorization module's own,
        // because this module does not depend on Authorization and reaching Spatie
        // directly is reserved to the module that owns it. The boundary reports nothing
        // for an account that does not participate, so a regular user gets empty lists
        // rather than a leak of the admin catalogue.
        return $this->successResponse(new AuthenticatedUserResource(
            $user,
            $token instanceof PersonalAccessToken ? $token->abilities : [],
            $user === null ? [] : $this->grants->rolesFor($user),
            $user === null ? [] : $this->grants->permissionsFor($user),
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
