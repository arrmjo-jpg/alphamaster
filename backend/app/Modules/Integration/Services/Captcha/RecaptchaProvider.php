<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Captcha;

use App\Modules\Integration\Contracts\CaptchaProviderContract;
use App\Modules\Integration\Data\CaptchaChallenge;
use App\Modules\Integration\Data\CaptchaResult;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Services\ProviderHttp;

/**
 * Google reCAPTCHA, over the HTTP client rather than a vendor SDK.
 *
 * Written the same way TwilioSmsProvider is, for the same reasons: no added
 * dependency, faithful to the vendor's actual request and error shape, and
 * exercisable through Http::fake().
 *
 * The secret key is a vendor credential and lives encrypted on the provider row,
 * where every other vendor credential on this platform lives (ADR 0017, ADR 0039).
 * The *site* key is public — a browser has to be given it — and is not stored here at
 * all; it belongs with the settings a client may read.
 */
class RecaptchaProvider implements CaptchaProviderContract
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function driver(): string
    {
        return 'recaptcha';
    }

    public function verify(CaptchaChallenge $challenge, IntegrationProvider $provider): CaptchaResult
    {
        $secret = (string) ($provider->getCredentials()['secret_key'] ?? '');

        if ($secret === '') {
            return CaptchaResult::failure(
                $this->driver(),
                'MISCONFIGURED',
                'The reCAPTCHA provider needs a secret_key credential.'
            );
        }

        $payload = ['secret' => $secret, 'response' => $challenge->token];

        if ($challenge->remoteIp !== null && $challenge->remoteIp !== '') {
            $payload['remoteip'] = $challenge->remoteIp;
        }

        try {
            $response = ProviderHttp::client()
                ->asForm()
                ->post(self::VERIFY_URL, $payload);
        } catch (\Throwable $e) {
            // Unlike an SMS send, a transport failure here is not a reason to try
            // someone else — nobody else can read this token. It is a refusal, and
            // the error code says why so an operator can tell it apart from a client
            // who genuinely failed the challenge.
            return CaptchaResult::failure($this->driver(), 'TRANSPORT_ERROR', $e->getMessage());
        }

        if (! $response->successful()) {
            return CaptchaResult::failure(
                $this->driver(),
                'HTTP_'.$response->status(),
                'The reCAPTCHA verification request failed.'
            );
        }

        // Google answers 200 with `success: false` for a bad token, so the HTTP status
        // says nothing on its own and the body is the verdict.
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $score = isset($body['score']) && is_numeric($body['score']) ? (float) $body['score'] : null;

        if (($body['success'] ?? false) !== true) {
            /** @var array<int, string> $codes */
            $codes = is_array($body['error-codes'] ?? null) ? $body['error-codes'] : [];

            return CaptchaResult::failure(
                $this->driver(),
                'REJECTED',
                $codes === [] ? 'The captcha response was rejected.' : implode(', ', $codes),
                $score
            );
        }

        $hostname = isset($body['hostname']) && is_string($body['hostname']) ? $body['hostname'] : null;

        // reCAPTCHA v3 returns a score instead of a pass/fail judgement, so `success`
        // alone means only that the token was well-formed and unused — a bot's token
        // scores badly and still comes back successful. Without a threshold, a v3
        // provider therefore admits everything, which is why the comparison happens
        // here rather than being left to each consumer to remember.
        //
        // The threshold is optional because v2 has no score to compare: absent either
        // the setting or the score, there is nothing to test and the vendor's verdict
        // stands on its own.
        $minimum = $provider->settings['minimum_score'] ?? null;

        if ($score !== null && is_numeric($minimum) && $score < (float) $minimum) {
            return CaptchaResult::failure(
                $this->driver(),
                'SCORE_BELOW_MINIMUM',
                'The captcha score '.$score.' is below the configured minimum of '.$minimum.'.',
                $score
            );
        }

        return CaptchaResult::success($this->driver(), $hostname, $score);
    }
}
