<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingReach;
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
            //
            // And nothing authenticates with it. There is no header check and no
            // machine-to-machine path — this is a credential for a caller that does not
            // exist yet, and it says so rather than sitting beside the sign-in limits
            // as though it guarded something.
            new SettingDefinition(
                group: 'security',
                key: 'api_secret_key',
                type: SettingType::STRING,
                isSecret: true,
                reach: SettingReach::AWAITING,
            ),
        ];
    }
}
