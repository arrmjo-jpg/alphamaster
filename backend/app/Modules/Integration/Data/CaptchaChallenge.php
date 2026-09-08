<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

/**
 * One captcha response, as presented by a client and about to be checked.
 *
 * `token` is what the widget produced in the browser. It is opaque here and stays
 * opaque: this module verifies it with the vendor that issued it and never inspects,
 * stores or logs it.
 *
 * `remoteIp` is optional because it is optional to the vendor. Sending it lets the
 * verification account for where the token was used, and omitting it is a supported
 * verification rather than a degraded one.
 */
final readonly class CaptchaChallenge
{
    public function __construct(
        public string $token,
        public ?string $remoteIp = null,
    ) {}
}
