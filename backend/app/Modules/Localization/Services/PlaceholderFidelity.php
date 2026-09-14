<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

/**
 * Whether a translation kept every placeholder and plural marker of its source (ADR 0049).
 *
 * A translation that loses `:name` sends a message with a literal colon-word where a person's
 * name should be; one that loses `{{count}}` renders a number nobody can see; one that invents
 * `:email` renders nothing where it stands. The platform's placeholders are:
 *
 *   * `:name` — Laravel messages and notification wording;
 *   * `{{name}}` — the console (i18next), including `$t(key)` references;
 *   * Laravel's plural syntax — `|` between forms and `{0}` / `[2,*]` range markers.
 *
 * The same set, the same number of each, in any order: word order legitimately moves a
 * placeholder within a sentence.
 */
final class PlaceholderFidelity
{
    public static function preserved(string $source, string $translation): bool
    {
        return self::signature($source) === self::signature($translation);
    }

    /**
     * @return array{placeholders: list<string>, forms: int, ranges: list<string>}
     */
    public static function signature(string $text): array
    {
        $placeholders = [];

        preg_match_all('/\{\{\s*([\w.-]+)\s*\}\}/u', $text, $braces);

        foreach ($braces[1] as $name) {
            $placeholders[] = '{{'.$name.'}}';
        }

        // A colon-word not preceded by a word character, a colon or a slash, so `https://`,
        // `10:30` and `group::key` are not placeholders.
        preg_match_all('/(?<![\w:\/]):([A-Za-z_][A-Za-z0-9_]*)/u', $text, $colons);

        foreach ($colons[1] as $name) {
            $placeholders[] = ':'.$name;
        }

        preg_match_all('/\$t\(([^)]*)\)/u', $text, $references);

        foreach ($references[1] as $reference) {
            $placeholders[] = '$t('.trim($reference).')';
        }

        sort($placeholders);

        preg_match_all('/(?:^|\|)\s*(\{\d+\}|\[\d+\s*,\s*(?:\d+|\*)\])/u', $text, $markers);
        $ranges = array_map(static fn (string $marker): string => (string) preg_replace('/\s+/', '', $marker), $markers[1]);
        sort($ranges);

        return [
            'placeholders' => $placeholders,
            'forms' => substr_count($text, '|') + 1,
            'ranges' => $ranges,
        ];
    }
}
