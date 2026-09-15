<?php

declare(strict_types=1);

namespace App\Modules\Pages\Services;

use App\Modules\Core\Translation\TranslationProgress;
use App\Modules\Pages\Models\PageTranslation;

/**
 * What a page's translation must hold (ADR 0055 §4).
 *
 * A translation is complete when it has a title, an address and a body. A summary is
 * optional. Completeness decides whether the page may be served in that language, and the
 * default language's decides whether it may be published at all.
 */
final class PageContent
{
    /** @var list<string> */
    public const FIELDS = ['title', 'slug', 'summary', 'body'];

    /** @var list<string> */
    public const REQUIRED = ['title', 'slug', 'body'];

    public static function progress(?PageTranslation $translation): TranslationProgress
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $values[$field] = $translation?->getAttribute($field);
        }

        return TranslationProgress::of($values, self::REQUIRED);
    }

    public static function isComplete(?PageTranslation $translation): bool
    {
        return self::progress($translation)->complete;
    }
}
