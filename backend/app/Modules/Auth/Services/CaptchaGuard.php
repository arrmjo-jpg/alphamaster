<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Integration\Contracts\CaptchaVerifierContract;
use App\Modules\Integration\Data\CaptchaChallenge;
use App\Modules\Integration\Exceptions\NoProviderConfiguredException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether a sign-in attempt has cleared the captcha, when the platform asks for one.
 *
 * One question, answered yes or no, and every uncertainty resolves to no.
 *
 * ## Fail-closed, exhaustively
 *
 * A captcha exists to make automated attempts expensive. The cheapest way to defeat
 * one that fails open is to make its vendor unreachable — which an attacker with any
 * volume can do incidentally, without meaning to. So the only route to `true` is a
 * verification that actually succeeded:
 *
 *   * the switch is off — no captcha is being asked for, so nothing has failed;
 *   * the vendor verified the response.
 *
 * Everything else is `false`: no token, an empty token, a rejected token, a timeout,
 * an HTTP error, a body that is not the shape the vendor documents, unreadable
 * credentials, a provider with no secret, no active provider at all, and any
 * exception this has not thought of. The last of those is the important one — a
 * `catch (Throwable)` here is not laziness, it is the statement that an unanticipated
 * failure must not become an admission.
 *
 * ## Why a missing token is not a validation error
 *
 * It would be easy to make `captcha_token` a required field and answer 422. That
 * would be a different response from a failed captcha, and a different response is a
 * signal: it tells a caller which check it tripped. The endpoint spends the rest of
 * its effort refusing to say that, so a missing token is simply a captcha that did
 * not pass, and the caller receives what every other refusal produces.
 *
 * ## What is recorded, and where
 *
 * The reason is never in the response and is always in the record. Every verification
 * attempt — pass, rejection, timeout — leaves a row in the integration usage log with
 * its error code, so an operator can tell "the vendor is down" from "clients are
 * failing the challenge". The one case with no such row is having no provider at all,
 * because nothing was attempted; that is a misconfiguration that would otherwise lock
 * every account out in silence, so it is logged here.
 */
class CaptchaGuard
{
    public function __construct(private readonly CaptchaVerifierContract $verifier) {}

    /**
     * Whether the platform is currently asking for a captcha.
     */
    public function isEnabled(): bool
    {
        return setting('auth.captcha_enabled', false) === true;
    }

    /**
     * Whether this request may proceed to have its credentials checked.
     */
    public function passes(Request $request): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        $token = $request->input('captcha_token');

        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            // The caller's address goes to the vendor where there is one. It is
            // advisory to the verification rather than required by it.
            return $this->verifier->verify(new CaptchaChallenge($token, $request->ip()))->successful;
        } catch (NoProviderConfiguredException $e) {
            // Enabled with nothing behind it. Every sign-in on this platform is now
            // being refused, and nothing else would say so.
            Log::error('Captcha is enabled but no active provider is configured; every sign-in is being refused.', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::error('Captcha verification raised unexpectedly; the attempt is refused.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
