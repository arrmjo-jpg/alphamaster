<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * Formatting and zone settings.
 *
 * The active language set and the default locale are not here: they are rows in
 * `languages`, managed through the Localization module (ADR 0015), and a second
 * copy in Settings would be a second source of truth for the same fact.
 */
class LocalizationCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            new SettingDefinition(
                group: 'localization',
                key: 'timezone',
                type: SettingType::STRING,
                default: 'UTC',
                nullable: false,
                rules: ['string', 'timezone'],
                isPublic: true,
            ),
            new SettingDefinition(
                group: 'localization',
                key: 'date_format',
                type: SettingType::STRING,
                default: 'Y-m-d',
                nullable: false,
                rules: ['string', 'max:50'],
                isPublic: true,
            ),
        ];
    }
}
