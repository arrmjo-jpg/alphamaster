<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\CaptchaVerifierContract;
use App\Modules\Integration\Data\CaptchaChallenge;
use App\Modules\Integration\Data\CaptchaResult;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Exceptions\CredentialDecryptionException;
use App\Modules\Integration\Exceptions\NoProviderConfiguredException;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;

/**
 * Verifies through the configured provider and records every attempt (ADR 0017).
 *
 * ## Why this does not fail over, when SmsDispatcher does
 *
 * The two look alike and differ in the one place that matters. A text message is
 * addressed to a phone number, and any vendor can carry it — so when the first fails,
 * trying the next is a better outcome for the same request.
 *
 * A captcha token is not like that. It is minted by one vendor for one site key and
 * is meaningless to anyone else: handing a Google token to a second provider does not
 * produce a second opinion, it produces a rejection that says nothing about the
 * client. Retrying across providers would therefore convert "the vendor is down" into
 * "the user failed the captcha", which is a worse answer than the honest one, and it
 * would spend a request against every configured vendor to get there.
 *
 * So the chain is used to *select* — the same ordering, the same active and default
 * semantics as every other capability — and the selected provider's answer is final.
 * The absence of failover is a property of the capability, not an omission in the
 * implementation, which is why it is stated here rather than left to be inferred.
 *
 * ## Why a refusal is the only failure mode
 *
 * Every path that cannot produce a verified pass produces a failure result: a vendor
 * rejection, unreadable credentials, a driver fault, an unreachable vendor. A captcha
 * that lets a request through because the vendor timed out is not a captcha, and the
 * cheapest way to defeat one is to make its vendor unreachable.
 *
 * The one thing that is *not* a failure result is having no provider at all. That is a
 * misconfiguration rather than a verdict, and returning it as a verdict would let a
 * platform that never finished wiring up its captcha report an endless stream of
 * failed challenges. It raises instead.
 */
class CaptchaVerifier implements CaptchaVerifierContract
{
    public function __construct(private readonly CaptchaManager $manager) {}

    /**
     * @throws NoProviderConfiguredException
     */
    public function verify(CaptchaChallenge $challenge): CaptchaResult
    {
        $provider = $this->manager->providerChain()->first();

        if (! $provider instanceof IntegrationProvider) {
            throw new NoProviderConfiguredException($this->manager->capability());
        }

        return $this->attempt($challenge, $provider);
    }

    /**
     * One verification against one provider, timed and recorded.
     */
    private function attempt(CaptchaChallenge $challenge, IntegrationProvider $provider): CaptchaResult
    {
        $startedAt = hrtime(true);

        try {
            $result = $this->manager->driver($provider->driver)->verify($challenge, $provider);
        } catch (CredentialDecryptionException $e) {
            // Unreadable credentials mean this provider cannot answer. For SMS that is
            // a reason to try the next vendor; here there is no next vendor to try, so
            // it is simply a refusal — and an operator sees it in the usage log.
            $result = CaptchaResult::failure($provider->driver, 'CREDENTIALS_UNREADABLE', $e->getMessage());
        } catch (\Throwable $e) {
            $result = CaptchaResult::failure($provider->driver, 'DRIVER_ERROR', $e->getMessage());
        }

        $this->record($provider, $result, (int) ((hrtime(true) - $startedAt) / 1_000_000));

        return $result;
    }

    /**
     * Persist the attempt. The token is deliberately absent, exactly as an SMS body
     * is: a usage log is for operating the integration, and a captcha token is a
     * credential for the moment it is alive.
     */
    private function record(IntegrationProvider $provider, CaptchaResult $result, int $durationMs): void
    {
        IntegrationUsageLog::query()->create([
            'integration_provider_id' => $provider->id,
            'capability' => $provider->capability,
            'driver' => $provider->driver,
            'status' => $result->successful ? UsageStatus::SUCCESS : UsageStatus::FAILURE,
            'reference' => $result->reference,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'duration_ms' => $durationMs,
        ]);
    }
}
