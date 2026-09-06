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
     * The three below store and convert exactly as a string does. They exist because
     * a type is not only a conversion: it is what tells validation which rule to
     * apply and tells a client which control to render. `media` in particular is
     * required by ADR 0018, which defers branding until a setting's value can be
     * validated as an existing media id rather than as an opaque string.
     */
    case URL = 'url';
    case EMAIL = 'email';
    case MEDIA = 'media';

    /**
     * All backing values, for validation rules and DB constraint assertions.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether this type is stored and converted as a plain string.
     *
     * The distinction these types carry is in validation and presentation, not in
     * conversion, and this is the one place that says so — so a `match` over the
     * cases cannot drift from it.
     */
    public function isStringBacked(): bool
    {
        return match ($this) {
            self::STRING, self::URL, self::EMAIL, self::MEDIA => true,
            self::INTEGER, self::FLOAT, self::BOOLEAN, self::JSON => false,
        };
    }
}
