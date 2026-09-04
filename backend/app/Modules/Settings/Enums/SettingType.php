<?php

declare(strict_types=1);

namespace App\Modules\Settings\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

enum SettingType: string
{
    use HasDisplayLabel;

    case STRING = 'string';
    case INTEGER = 'integer';
    case FLOAT = 'float';
    case BOOLEAN = 'boolean';
    case JSON = 'json';

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
