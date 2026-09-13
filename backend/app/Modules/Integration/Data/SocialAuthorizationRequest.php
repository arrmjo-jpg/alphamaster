<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

/**
 * What a social provider needs to send a person to sign in (ADR 0050 §8).
 *
 * Every value here was generated or validated by the platform before it arrived. The
 * driver builds a URL from them and decides nothing: the state and nonce are the
 * platform's, and the challenge is the client's PKCE commitment, never its verifier.
 */
final readonly class SocialAuthorizationRequest
{
    public function __construct(
        public string $redirectUri,
        public string $state,
        public string $nonce,
        public string $codeChallenge,
    ) {}
}
