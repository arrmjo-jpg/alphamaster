<?php

declare(strict_types=1);

namespace App\Modules\Auth\Contracts;

use App\Modules\Auth\Enums\MfaType;
use App\Modules\User\Models\User;

interface MfaManagerContract
{
    /**
     * Resolve the implementation for a method type.
     */
    public function driver(MfaType $type): MfaMethodContract;

    /**
     * Whether the user has at least one confirmed method.
     */
    public function isEnabled(User $user): bool;

    /**
     * Begin enrolment for a method, returning the setup payload.
     *
     * @return array{secret: string, uri: string}
     */
    public function enrol(User $user, MfaType $type): array;

    /**
     * Confirm an enrolment with a valid code, activating the method and returning
     * freshly generated recovery codes in plaintext, once.
     *
     * @return array<int, string>
     */
    public function confirm(User $user, MfaType $type, string $code): array;

    /**
     * Disable every method and destroy all recovery codes for the user.
     */
    public function disable(User $user): void;

    /**
     * Verify a challenge response: a code from any confirmed method, or a recovery
     * code. A recovery code is consumed on use.
     */
    public function verifyChallenge(User $user, string $code): bool;
}
