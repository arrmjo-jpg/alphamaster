<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * Authentication behaviour an operator may change at runtime.
 *
 * Not to be confused with bootstrap-critical authentication configuration — the
 * guard, the token driver, the encryption key — which stays in ENV because the
 * application must be able to authenticate before it can read a setting.
 */
class AuthCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            new SettingDefinition(
                group: 'auth',
                key: 'registration_enabled',
                type: SettingType::BOOLEAN,
                default: true,
                nullable: false,
                isPublic: true,
            ),
            new SettingDefinition(
                group: 'auth',
                key: 'password_min_length',
                type: SettingType::INTEGER,
                default: 8,
                nullable: false,
                rules: ['integer', 'min:8', 'max:128'],
                isPublic: true,
            ),
            new SettingDefinition(
                group: 'auth',
                key: 'session_lifetime',
                type: SettingType::INTEGER,
                default: 120,
                nullable: false,
                rules: ['integer', 'min:1', 'max:20160'],
            ),
        ];
    }
}
