<?php

declare(strict_types=1);

namespace App\Modules\Auth\Contracts;

use App\Modules\Auth\Enums\MfaType;
use App\Modules\User\Models\User;

/**
 * A single multi-factor method (ADR 0013).
 *
 * Implementations own their own secret material and verification rules. Adding
 * WebAuthn or an OTP channel later means implementing this contract, not changing
 * the challenge flow that consumes it.
 */
interface MfaMethodContract
{
    /**
     * The method this implementation provides.
     */
    public function type(): MfaType;

    /**
     * Begin enrolment: generate secret material and whatever the client needs to
     * complete setup. The method is not active until confirm() succeeds.
     *
     * @return array{secret: string, uri: string}
     */
    public function enrol(User $user): array;

    /**
     * Verify a code against the user's stored secret for this method.
     */
    public function verify(User $user, string $code): bool;
}
