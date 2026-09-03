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
     * The ability a freshly authenticated user's token should carry.
     */
    public static function forAdministrator(bool $isAdmin): self
    {
        return $isAdmin ? self::ADMIN_ACCESS : self::USER_ACCESS;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
