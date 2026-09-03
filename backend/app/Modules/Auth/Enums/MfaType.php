<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

enum MfaType: string
{
    case TOTP = 'totp';

    /**
     * All backing values, for validation rules and DB constraint assertions.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
