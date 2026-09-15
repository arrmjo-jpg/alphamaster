<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\ConstrainsSettings;
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
 *
 * The duration limits apply to analysis requests only. Uploading a video is never refused
 * over them: a ten-minute video is stored as it always was, and an analysis of it is refused
 * as too long when something asks for one.
 */
class MediaAnalysisCatalogue implements ConstrainsSettings
{
    private const MIN_DURATION = 'media_analysis.min_video_duration_seconds';

    private const MAX_DURATION = 'media_analysis.max_video_duration_seconds';

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
            // Seconds, stored as a number and presented as a duration. Empty is no limit; 0
            // is a real limit, the same as empty for a minimum and refusing everything with
            // a length for a maximum.
            new SettingDefinition(
                group: 'media_analysis',
                key: 'min_video_duration_seconds',
                type: SettingType::INTEGER,
                rules: ['nullable', 'integer', 'between:0,86400'],
                unit: 'seconds',
            ),
            new SettingDefinition(
                group: 'media_analysis',
                key: 'max_video_duration_seconds',
                type: SettingType::INTEGER,
                rules: ['nullable', 'integer', 'between:0,86400'],
                unit: 'seconds',
            ),
            new SettingDefinition(
                group: 'media_analysis',
                key: 'max_file_size_mb',
                type: SettingType::INTEGER,
                rules: ['nullable', 'integer', 'between:1,102400'],
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
                unit: 'seconds',
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

    /**
     * A minimum longer than the maximum would refuse every video, and is refused instead.
     */
    public function violations(array $values): array
    {
        if (! array_key_exists(self::MIN_DURATION, $values) || ! array_key_exists(self::MAX_DURATION, $values)) {
            return [];
        }

        $minimum = $values[self::MIN_DURATION];
        $maximum = $values[self::MAX_DURATION];

        if (is_int($minimum) && is_int($maximum) && $minimum > $maximum) {
            $message = __('setting.media_analysis.duration_range_invalid');

            return [self::MIN_DURATION => is_string($message) ? $message : 'setting.media_analysis.duration_range_invalid'];
        }

        return [];
    }
}
