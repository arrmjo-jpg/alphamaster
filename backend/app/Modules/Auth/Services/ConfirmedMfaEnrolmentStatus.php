<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\MfaMethod;
use App\Modules\Core\Contracts\MfaEnrolmentStatus;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Answers the Core contract from the one thing that actually proves protection: a
 * confirmed method row.
 *
 * Confirmed, and only confirmed. An enrolment that was begun and abandoned leaves a
 * row behind, and counting it would report an account as protected by a factor
 * nobody has ever presented — which is the opposite of what an administrator reading
 * this column needs to know.
 *
 * Nothing here reads a secret, a destination or a method name. The contract returns a
 * boolean and this returns a boolean; there is deliberately no shape in which more
 * could leak.
 */
class ConfirmedMfaEnrolmentStatus implements MfaEnrolmentStatus
{
    public function isEnrolled(Authenticatable $user): bool
    {
        $id = $user->getAuthIdentifier();

        if (! is_string($id) || $id === '') {
            return false;
        }

        return MfaMethod::query()->where('user_id', $id)->confirmed()->exists();
    }
}
