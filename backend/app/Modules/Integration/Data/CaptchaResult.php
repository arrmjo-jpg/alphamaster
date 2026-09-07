<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

/**
 * The outcome of verifying one captcha response against one driver.
 *
 * Shaped after SmsResult, and different from it in one way that matters. A driver
 * reports both a vendor rejection and a transport failure by returning this rather
 * than throwing — but the two are not the same outcome, and a caller acting on this
 * result must not treat them as one.
 *
 * `successful === false` means only that the request may not proceed. Whether that is
 * because the vendor said the token is bad, or because the vendor could not be
 * reached, is in `errorCode`. Both refuse; only the first is evidence about the
 * client. Anything that decides to let a request through must read this as a refusal
 * either way — a captcha that fails open when the vendor is down is not a captcha.
 */
final readonly class CaptchaResult
{
    private function __construct(
        public bool $successful,
        public string $driver,
        public ?string $reference = null,
        public ?float $score = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @param  string|null  $reference  the hostname the token was solved on, where the
     *                                  vendor reports one — useful for noticing a token
     *                                  minted for a different site
     * @param  float|null  $score  present for score-based versions only
     */
    public static function success(string $driver, ?string $reference = null, ?float $score = null): self
    {
        return new self(true, $driver, $reference, $score);
    }

    public static function failure(
        string $driver,
        string $errorCode,
        string $errorMessage,
        ?float $score = null,
    ): self {
        return new self(false, $driver, null, $score, $errorCode, $errorMessage);
    }
}
