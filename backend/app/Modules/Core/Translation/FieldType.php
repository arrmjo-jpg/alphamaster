<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

/**
 * What kind of text a translatable field holds (ADR 0056).
 *
 * It decides how the text is translated: plain text is translated as text, HTML has its text
 * translated and its structure left exactly as it was.
 */
enum FieldType: string
{
    case PLAIN_TEXT = 'plain_text';
    case HTML = 'html';
}
