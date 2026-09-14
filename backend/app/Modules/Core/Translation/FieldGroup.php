<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

/**
 * What a translatable field is for (ADR 0056).
 *
 * Content is what a reader reads; SEO is what a search engine or a link preview reads, and is
 * translated with its length limit in mind.
 */
enum FieldGroup: string
{
    case CONTENT = 'content';
    case SEO = 'seo';
}
