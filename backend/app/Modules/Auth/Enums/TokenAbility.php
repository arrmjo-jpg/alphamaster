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
     * Granted only to an administrator whose email address is not yet verified.
     *
     * It reaches one endpoint — the request for a verification link — and nothing
     * else. Not `me`, not `logout`, not enrolment, and least of all an administrative
     * route: the perimeter demands admin:access and this is not it.
     *
     * It exists for the same reason MFA_ENROL does, and resolves the same shape of
     * deadlock. Verification is now a precondition of administrative access, so an
     * unverified administrator holds no access token; but the endpoint that sends the
     * link is authenticated, because one that took an address would mail strangers.
     * Something has to bridge those two facts, and a narrowly scoped ability is the
     * bridge the perimeter already knows how to enforce.
     */
    case EMAIL_VERIFY = 'email:verify';

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
     * MFA_ENROL and EMAIL_VERIFY are deliberately absent: each is a credential for
     * finishing one prerequisite, and neither is a sign-in.
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
