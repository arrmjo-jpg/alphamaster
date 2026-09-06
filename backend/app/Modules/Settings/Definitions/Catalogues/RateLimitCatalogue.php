<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * The ceilings the central limiter reads (ADR 0022, Phase 14).
 *
 * Internal rather than public: publishing your own rate limits tells a caller
 * exactly how much traffic goes unnoticed.
 */
class RateLimitCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            new SettingDefinition(
                group: 'rate_limit',
                key: 'read_per_minute',
                type: SettingType::INTEGER,
                default: 120,
                nullable: false,
                rules: ['integer', 'min:1', 'max:100000'],
            ),
            new SettingDefinition(
                group: 'rate_limit',
                key: 'write_per_minute',
                type: SettingType::INTEGER,
                default: 30,
                nullable: false,
                rules: ['integer', 'min:1', 'max:100000'],
            ),
            new SettingDefinition(
                group: 'rate_limit',
                key: 'public_read_per_minute',
                type: SettingType::INTEGER,
                default: 60,
                nullable: false,
                rules: ['integer', 'min:1', 'max:100000'],
            ),
            new SettingDefinition(
                group: 'rate_limit',
                key: 'upload_per_hour',
                type: SettingType::INTEGER,
                default: 20,
                nullable: false,
                rules: ['integer', 'min:1', 'max:100000'],
            ),
            new SettingDefinition(
                group: 'rate_limit',
                key: 'authenticated_ip_multiplier',
                type: SettingType::INTEGER,
                default: 4,
                nullable: false,
                rules: ['integer', 'min:1', 'max:100'],
            ),
        ];
    }
}
