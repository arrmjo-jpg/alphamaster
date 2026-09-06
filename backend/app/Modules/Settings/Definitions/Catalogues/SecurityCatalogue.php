<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * The platform's own defences.
 *
 * These change how the application protects itself, so they carry a permission of
 * their own beyond `settings.update`: an administrator who may rename the site is
 * not thereby entitled to widen the login throttle.
 */
class SecurityCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            new SettingDefinition(
                group: 'security',
                key: 'max_login_attempts',
                type: SettingType::INTEGER,
                default: 5,
                nullable: false,
                rules: ['integer', 'min:1', 'max:100'],
            ),
            new SettingDefinition(
                group: 'security',
                key: 'decay_minutes',
                type: SettingType::INTEGER,
                default: 1,
                nullable: false,
                rules: ['integer', 'min:1', 'max:1440'],
            ),
            // Provisioned unset. Generating or rotating secret material is an operator
            // action, never a side effect of provisioning (ADR 0018).
            new SettingDefinition(
                group: 'security',
                key: 'api_secret_key',
                type: SettingType::STRING,
                isSecret: true,
            ),
        ];
    }
}
