<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

/**
 * Whether a translated HTML fragment kept the markup of its source (ADR 0056).
 *
 * A model asked to translate HTML is asked to translate the words and leave the rest. This is
 * how the platform finds out whether it did, before anybody is shown the result: the same
 * tags, as many of each, and the same technical attribute values — the links, the images, the
 * classes and anchors. Word order legitimately moves an inline tag within a sentence, so the
 * comparison is of what is there rather than of where.
 *
 * Text a person reads in an attribute — `alt`, `title` — is expected to change, and is not
 * compared.
 */
final class HtmlFidelity
{
    /** Attributes whose values are addresses or hooks, never language. */
    private const TECHNICAL_ATTRIBUTES = ['href', 'src', 'srcset', 'id', 'class', 'target', 'rel', 'name', 'colspan', 'rowspan', 'width', 'height', 'start', 'type'];

    public static function preserved(string $source, string $translation): bool
    {
        return self::signature($source) === self::signature($translation);
    }

    /**
     * @return array{tags: list<string>, attributes: list<string>}
     */
    private static function signature(string $html): array
    {
        $tags = [];
        $attributes = [];

        preg_match_all('/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9-]*)([^>]*)>/', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = strtolower($match[2]);
            $tags[] = ($match[1] === '/' ? '/' : '').$name;

            preg_match_all('/([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $match[3], $pairs, PREG_SET_ORDER);

            foreach ($pairs as $pair) {
                $attribute = strtolower($pair[1]);

                if (in_array($attribute, self::TECHNICAL_ATTRIBUTES, true) || str_starts_with($attribute, 'data-')) {
                    $value = ($pair[2] ?? '') !== '' ? $pair[2] : (($pair[3] ?? '') !== '' ? $pair[3] : ($pair[4] ?? ''));
                    $attributes[] = $name.'.'.$attribute.'='.trim($value);
                }
            }
        }

        sort($tags);
        sort($attributes);

        return ['tags' => $tags, 'attributes' => $attributes];
    }
}
