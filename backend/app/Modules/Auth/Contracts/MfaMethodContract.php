<?php

declare(strict_types=1);

namespace App\Modules\Auth\Contracts;

use App\Modules\Auth\Data\MfaEnrolment;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\User\Models\User;

/**
 * A single multi-factor method (ADR 0013).
 *
 * Implementations own their own secret material and verification rules. Adding a
 * factor means implementing this contract, not changing the challenge flow that
 * consumes it.
 */
interface MfaMethodContract
{
    /**
     * The method this implementation provides.
     */
    public function type(): MfaType;

    /**
     * Begin enrolment, returning whatever the client needs to complete setup. The
     * method is not active until confirm() succeeds.
     *
     * @param  array<string, mixed>  $options  method-specific enrolment input
     */
    public function enrol(User $user, array $options = []): MfaEnrolment;

    /**
     * Verify a code for this method.
     */
    public function verify(User $user, string $code): bool;
}
