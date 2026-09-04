<?php

declare(strict_types=1);

namespace App\Modules\User\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * The kind of account, and the boundary that separates administration from
 * ordinary application use.
 *
 * This is the discriminator the whole authorization story rests on: only an ADMIN
 * account may hold the admin:access token ability, enter the admin perimeter, or
 * participate in the Spatie role and permission system. A USER account is an
 * application user and never acquires administrative capability, whatever database
 * relations it may end up holding.
 */
enum AccountType: string
{
    use HasDisplayLabel;

    case ADMIN = 'admin';
    case USER = 'user';

    /**
     * Whether accounts of this type may participate in admin RBAC at all.
     */
    public function participatesInAdminRbac(): bool
    {
        return $this === self::ADMIN;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
