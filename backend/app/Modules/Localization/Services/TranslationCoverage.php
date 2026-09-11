<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Core\Translation\TranslationSource;

/**
 * How far each language has got — the only place that is counted (ADR 0048 §3).
 *
 * The workshop's counts and the Languages page both read this, so "French is at 40%"
 * cannot mean one thing on one screen and another on the next.
 *
 * Three rules, all inherited rather than invented here:
 *
 * - **Fields, not items** (ADR 0043 §4). A template with a French subject over an
 *   English body is not half a French template in any sense a reader would recognise.
 * - **Only saved text counts.** An AI suggestion nobody has accepted is not a
 *   translation, and nothing falls back: an empty value is the same absence as a
 *   missing row.
 * - **Only what the caller may view.** A count of untranslated notification wording is
 *   a statement about content an operator without `notifications.view` was not granted
 *   (ADR 0043 §3), so coverage is per caller.
 */
class TranslationCoverage
{
    public function __construct(private readonly TranslationRegistry $registry) {}

    /**
     * Whether a caller holding these permissions may read a source.
     *
     * @param  array<int, string>  $permissions
     */
    public function mayView(TranslationSource $source, array $permissions): bool
    {
        $required = $source->viewPermission();

        return $required === null || in_array($required, $permissions, true);
    }

    /**
     * The sources a caller may read, in registration order.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, TranslationSource>
     */
    public function viewableSources(array $permissions): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            fn (TranslationSource $source): bool => $this->mayView($source, $permissions)
        ));
    }

    /**
     * Written fields out of all fields, per locale, for one body of entries.
     *
     * @param  array<int, TranslationEntry>  $entries
     * @param  array<int, string>  $locales
     * @return array<string, array{total: int, translated: int}>
     */
    public function count(array $entries, array $locales): array
    {
        $counts = [];

        foreach ($locales as $locale) {
            $total = 0;
            $translated = 0;

            foreach ($entries as $entry) {
                foreach ($entry->fields as $field) {
                    $total++;

                    if ($field->hasValueFor($locale)) {
                        $translated++;
                    }
                }
            }

            $counts[$locale] = ['total' => $total, 'translated' => $translated];
        }

        return $counts;
    }

    /**
     * Coverage across everything the caller may read, per locale.
     *
     * @param  array<int, string>  $permissions
     * @param  array<int, string>  $locales
     * @return array<string, array{total: int, translated: int}>
     */
    public function overall(array $permissions, array $locales): array
    {
        $totals = array_fill_keys($locales, ['total' => 0, 'translated' => 0]);

        foreach ($this->viewableSources($permissions) as $source) {
            foreach ($this->count($source->entries(), $locales) as $locale => $counts) {
                $totals[$locale]['total'] += $counts['total'];
                $totals[$locale]['translated'] += $counts['translated'];
            }
        }

        return $totals;
    }
}
