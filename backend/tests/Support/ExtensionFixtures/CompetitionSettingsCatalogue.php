<?php

declare(strict_types=1);

namespace Tests\Support\ExtensionFixtures;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * A settings catalogue as a future domain module would register it (ADR 0052).
 */
final class CompetitionSettingsCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            new SettingDefinition(
                group: 'competitions',
                key: 'entries_per_page',
                type: SettingType::INTEGER,
                default: 20,
                nullable: false,
                rules: ['integer', 'min:1', 'max:100'],
            ),
        ];
    }
}
