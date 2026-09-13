<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

/**
 * Who a provider says signed in, after its ID token has been validated.
 *
 * `subject` is the identity. The email is what the provider reports, and
 * `emailVerified` is only whether the provider asserted that it verified it — it is
 * never local verification, and nothing writes it to an account (ADR 0050 §4).
 *
 * No provider token is carried here. The access token and ID token end their life in
 * the driver that redeemed them.
 */
final readonly class SocialProfile
{
    public function __construct(
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
    ) {}
}
