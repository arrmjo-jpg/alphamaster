<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * A content delivery network in front of public media (ADR 0036, ADR 0051 §7).
 *
 * The media URL resolver read `cdn.enabled` and `cdn.base_url` long before anything
 * declared them, so an operator had no way to set either. No vendor is named: a base URL
 * and a switch describe every CDN that fronts an origin. Purging needs a vendor driver,
 * and none is chosen.
 */
class CdnCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            // Off until an operator names the base URL. A switch that is on with no
            // base URL rewrites nothing, and the resolver treats it as off.
            new SettingDefinition(
                group: 'cdn',
                key: 'enabled',
                type: SettingType::BOOLEAN,
                default: false,
                nullable: false,
                dependsOn: ['cdn.base_url'],
            ),
            // Where public media is served from. `client_page_url` is ClientUrlPolicy:
            // a complete address with no credentials or fragment, and in production
            // https and never this machine. No default and no fallback.
            new SettingDefinition(
                group: 'cdn',
                key: 'base_url',
                type: SettingType::URL,
                rules: ['url:http,https', 'max:2048', 'client_page_url'],
            ),
        ];
    }
}
