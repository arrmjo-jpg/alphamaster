<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

/**
 * An authorization code to redeem, with what binds it to the flow that asked for it.
 *
 * The redirect URI and nonce come from the stored state, not from the callback request:
 * a client that could supply them could redeem a code under different terms than the
 * ones the platform issued.
 */
final readonly class SocialCodeExchange
{
    public function __construct(
        public string $code,
        public string $codeVerifier,
        public string $redirectUri,
        public string $nonce,
    ) {}
}
