<?php

declare(strict_types=1);

namespace App\Modules\Core\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Rich text an operator writes and a visitor reads (ADR 0055 §10).
 *
 * Sanitised when it is written, so what is stored is what is served and nothing downstream
 * has to remember to clean it. An allow-list of structural and inline elements, links
 * restricted to http, https and mailto, and every link given `rel="noopener noreferrer"`.
 * Scripts, styles, event handlers, frames and forms do not survive, whatever they are
 * wrapped in.
 */
final class HtmlSanitizer
{
    /** Longer input is cut before sanitising, so a pasted document cannot exhaust a worker. */
    public const MAX_INPUT_LENGTH = 200000;

    private const BLOCKS = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'pre', 'hr', 'br', 'table', 'thead', 'tbody', 'tr'];

    private const INLINE = ['strong', 'em', 'b', 'i', 'u', 's', 'code', 'sub', 'sup', 'span'];

    private SymfonyHtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowRelativeLinks()
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        foreach ([...self::BLOCKS, ...self::INLINE] as $element) {
            $config = $config->allowElement($element);
        }

        $config = $config
            ->allowElement('a', ['href', 'title'])
            ->allowElement('th', ['colspan', 'rowspan', 'scope'])
            ->allowElement('td', ['colspan', 'rowspan'])
            // A paragraph in one language quoting another keeps its direction.
            ->allowAttribute('dir', ['p', 'blockquote', 'li', 'span', 'td', 'th'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer');

        $this->sanitizer = new SymfonyHtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return trim($this->sanitizer->sanitize($html));
    }

    /**
     * Whether sanitised markup holds any text a reader would see.
     */
    public static function hasText(?string $html): bool
    {
        if ($html === null) {
            return false;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $text)) !== '';
    }
}
