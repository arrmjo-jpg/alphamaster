<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationItemStatus;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Core\Translation\TranslationSource;

/**
 * How far each language has got — the only place that is counted (ADR 0048 §3).
 *
 * The workshop's counts and the Languages page both read this, so "French is at 40%" cannot
 * mean one thing on one screen and another on the next.
 *
 * Three rules:
 *
 * - **Items, not fields** (ADR 0056). An item is translated when every one of its required
 *   fields has text; a page without an SEO description is a translated page, and a template
 *   with a subject over no body is not a translated template.
 * - **Only saved text counts.** An AI translation nobody has accepted is not a translation, and
 *   nothing falls back: an empty value is the same absence as a missing row.
 * - **Only what the caller may view.** A count of untranslated notification wording is a
 *   statement about content an operator without `notifications.view` was not granted
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
     * Translated items out of all items, per locale, for one body of entries.
     *
     * An item with no translatable field is not counted at all: it is shown for context and has
     * nothing in it to translate.
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
                if ($entry->translatableFields() === []) {
                    continue;
                }

                $total++;

                if ($entry->statusIn($locale) === TranslationItemStatus::TRANSLATED) {
                    $translated++;
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
