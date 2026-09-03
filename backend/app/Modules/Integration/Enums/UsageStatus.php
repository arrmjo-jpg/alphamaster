<?php

declare(strict_types=1);

namespace App\Modules\Integration\Enums;

enum UsageStatus: string
{
    case SUCCESS = 'success';
    case FAILURE = 'failure';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
