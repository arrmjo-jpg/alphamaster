<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * Site-level settings every deployment has, whatever it is for.
 *
 * The localized ones carry `isLocalized` rather than a key per language. ADR 0018
 * rejects `site_name_ar` / `site_name_en` explicitly: the language set is
 * administrator-managed at runtime (ADR 0015), so a key per language would make
 * adding a language a migration.
 */
class GeneralCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            new SettingDefinition(
                group: 'general',
                key: 'site_name',
                type: SettingType::STRING,
                default: 'AlphaMaster Enterprise',
                nullable: false,
                rules: ['string', 'max:150'],
                isPublic: true,
                isLocalized: true,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'site_description',
                type: SettingType::STRING,
                default: 'Modern Modular SaaS & Foundation',
                rules: ['string', 'max:500'],
                isPublic: true,
                isLocalized: true,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'maintenance_mode',
                type: SettingType::BOOLEAN,
                default: false,
                nullable: false,
                isPublic: true,
            ),
        ];
    }
}
