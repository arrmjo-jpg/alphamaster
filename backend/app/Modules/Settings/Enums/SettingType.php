<?php

declare(strict_types=1);

namespace App\Modules\Settings\Enums;

enum SettingType: string
{
    case STRING = 'string';
    case INTEGER = 'integer';
    case FLOAT = 'float';
    case BOOLEAN = 'boolean';
    case JSON = 'json';

    /**
     * Determine if a given type string is valid.
     */
    public static function isValid(string $type): bool
    {
        return self::tryFrom($type) !== null;
    }
}
