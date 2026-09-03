<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

/**
 * Sanctum token abilities as defined by ADR 0012.
 *
 * A token carries exactly one of these. Administrative endpoints demand
 * `admin:access` at the ability layer, before any route or policy logic runs, so
 * that a regular user's token cannot reach them even if every later check were
 * misconfigured.
 */
enum TokenAbility: string
{
    case ADMIN_ACCESS = 'admin:access';
    case USER_ACCESS = 'user:access';

    /**
     * Granted only to an administrator who has not yet enrolled a second factor.
     *
     * It reaches the MFA enrolment endpoints and nothing else: the perimeter demands
     * admin:access, so this token cannot touch an administrative route. It exists
     * because mandatory enrolment would otherwise be a deadlock — the administrator
     * needs a credential to enrol with, but must not hold an access token until they
     * have.
     */
    case MFA_ENROL = 'mfa:enrol';

    /**
     * The ability a fully authenticated user's token should carry.
     */
    public static function forAdministrator(bool $isAdmin): self
    {
        return $isAdmin ? self::ADMIN_ACCESS : self::USER_ACCESS;
    }

    /**
     * Abilities that represent a completed sign-in, as opposed to a partial one.
     *
     * @return array<int, string>
     */
    public static function accessAbilities(): array
    {
        return [self::ADMIN_ACCESS->value, self::USER_ACCESS->value];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
