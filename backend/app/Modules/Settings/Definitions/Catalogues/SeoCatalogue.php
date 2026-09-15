<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingReach;
use App\Modules\Settings\Enums\SettingType;

/**
 * The site's own search settings (ADR 0058 §3, §4).
 *
 * The site's name, description, default sharing image and public origin already exist in
 * General and Branding; what search adds is a robots policy for content that sets none, and
 * the extra lines an operator puts in the public robots.txt. Both are published, because both
 * end up in public documents.
 */
class SeoCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            new SettingDefinition(
                group: 'seo',
                key: 'robots_policy',
                type: SettingType::STRING,
                default: 'index,follow',
                nullable: false,
                rules: ['string', 'regex:/^(index|noindex),(follow|nofollow)$/'],
                isPublic: true,
                reach: SettingReach::PLATFORM,
            ),
            new SettingDefinition(
                group: 'seo',
                key: 'robots_extra',
                type: SettingType::STRING,
                default: null,
                nullable: true,
                rules: ['string', 'max:5000'],
                isPublic: true,
                reach: SettingReach::PLATFORM,
            ),
        ];
    }
}
