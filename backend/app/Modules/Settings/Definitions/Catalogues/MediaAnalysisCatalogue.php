<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * Media analysis — AI video detection — as an operator configures it (ADR 0054).
 *
 * Availability and limits, and nothing about where analysis is used. Switching it on makes the
 * capability available to consumers that call it; it never analyses media nobody asked about.
 * The vendor, its model and its credentials live on the Integration provider row.
 *
 * Every limit is unset by default. No number here is the platform guessing what an operator's
 * vendor, budget or hardware allows; the analyzer's own limits apply regardless, and an
 * operator sets stricter ones when they have a reason to.
 */
class MediaAnalysisCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            // Off by default. Analysis sends media to an analyzer, which may be a third party
            // and costs per file, so it is available only once an operator decides it is.
            new SettingDefinition(
                group: 'media_analysis',
                key: 'enabled',
                type: SettingType::BOOLEAN,
                default: false,
                nullable: false,
            ),
            new SettingDefinition(
                group: 'media_analysis',
                key: 'max_file_size_mb',
                type: SettingType::INTEGER,
                rules: ['nullable', 'integer', 'between:1,102400'],
            ),
            new SettingDefinition(
                group: 'media_analysis',
                key: 'max_duration_seconds',
                type: SettingType::INTEGER,
                rules: ['nullable', 'integer', 'between:1,86400'],
            ),
            new SettingDefinition(
                group: 'media_analysis',
                key: 'daily_limit',
                type: SettingType::INTEGER,
                rules: ['nullable', 'integer', 'between:1,1000000'],
            ),
            // How long one analysis may run before the worker gives up on it. Its own ceiling,
            // like `ai.timeout_seconds`, because a video takes far longer than a message.
            new SettingDefinition(
                group: 'media_analysis',
                key: 'timeout_seconds',
                type: SettingType::INTEGER,
                default: 300,
                nullable: false,
                rules: ['integer', 'between:30,1800'],
            ),
            // Unset, no classification is derived: scores are recorded and each consumer reads
            // them against its own threshold. Set, they produce an advisory classification
            // recorded with the policy version it came from.
            new SettingDefinition(
                group: 'media_analysis',
                key: 'likely_synthetic_threshold',
                type: SettingType::FLOAT,
                rules: ['nullable', 'numeric', 'between:0,1'],
            ),
            new SettingDefinition(
                group: 'media_analysis',
                key: 'likely_authentic_threshold',
                type: SettingType::FLOAT,
                rules: ['nullable', 'numeric', 'between:0,1'],
            ),
        ];
    }
}
