<?php

declare(strict_types=1);

namespace App\Modules\Auth\Contracts;

use App\Modules\Auth\Models\MfaMethod;
use App\Modules\User\Models\User;

/**
 * A method whose code must be delivered before it can be answered.
 *
 * Implemented only by methods that need it, so the challenge flow can ask whether
 * delivery applies rather than every method carrying a send() that most of them
 * would leave empty. TOTP deliberately does not implement this.
 */
interface DeliversMfaCodes
{
    /**
     * Generate and dispatch a fresh code, replacing any outstanding one.
     *
     * Returns the masked destination it was sent to, so a client can tell the user
     * where to look without the platform disclosing the full number.
     */
    public function deliver(User $user, MfaMethod $method): string;

    /**
     * How long a delivered code remains valid.
     */
    public function codeLifetimeSeconds(): int;

    /**
     * Minimum gap between deliveries, so a resend cannot become an SMS amplifier.
     */
    public function resendCooldownSeconds(): int;
}
