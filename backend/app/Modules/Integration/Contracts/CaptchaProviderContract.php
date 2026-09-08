<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\Modules\Integration\Data\CaptchaChallenge;
use App\Modules\Integration\Data\CaptchaResult;
use App\Modules\Integration\Models\IntegrationProvider;

/**
 * One captcha vendor.
 *
 * The mirror of SmsProviderContract, and configured the same way: the provider row
 * supplies the credentials and settings, so a vendor is wired up from the Admin UI
 * rather than from a config file (ADR 0017).
 */
interface CaptchaProviderContract
{
    /**
     * The driver name this implementation answers to.
     */
    public function driver(): string;

    /**
     * Check a response with the vendor.
     *
     * Returns a failure result rather than throwing for a rejection or an unreachable
     * vendor; exceptions stay reserved for programming and configuration faults, as
     * they are for SMS.
     */
    public function verify(CaptchaChallenge $challenge, IntegrationProvider $provider): CaptchaResult;
}
