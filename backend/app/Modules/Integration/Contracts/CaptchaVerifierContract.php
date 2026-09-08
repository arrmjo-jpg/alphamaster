<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\Modules\Integration\Data\CaptchaChallenge;
use App\Modules\Integration\Data\CaptchaResult;
use App\Modules\Integration\Exceptions\NoProviderConfiguredException;

/**
 * What a consumer depends on to check a captcha response.
 *
 * The counterpart of SmsDispatcherContract: selecting the configured provider and
 * recording the attempt are part of verifying, not the caller's job. A consumer
 * hands over a token and receives a verdict.
 *
 * The contract lives here, in Integration, and the consumer lives elsewhere. That
 * direction is enforced — the architecture suite forbids this module from importing
 * the modules that consume it — so adding a second consumer later changes nothing
 * here.
 */
interface CaptchaVerifierContract
{
    /**
     * Verify a response, or refuse.
     *
     * A false result is a refusal whatever its cause. There is deliberately no
     * "unknown" outcome: a caller offered one would eventually treat it as a pass,
     * and a captcha that passes when the vendor is unreachable protects nothing.
     *
     * @throws NoProviderConfiguredException when no active provider can answer, which
     *                                       is a misconfiguration rather than a verdict
     *                                       and must not be mistaken for one
     */
    public function verify(CaptchaChallenge $challenge): CaptchaResult;
}
