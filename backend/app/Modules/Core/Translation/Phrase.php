<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

/**
 * A piece of text named by its translation key, rendered in whichever language its
 * reader uses.
 *
 * Exists for placeholders. A template is rendered in the *recipient's* locale, on a
 * queue, long after the request that raised it has gone — so a producer that
 * translated its own wording would translate it into the language of whoever happened
 * to trigger the notification. An administrator working in English would send an
 * Arabic-speaking account an English sentence inside an Arabic message. Handing over
 * the key instead defers the choice of language to the one place that knows it.
 *
 * Plain data: serialisable onto a queue, and inert until something asks for a locale.
 */
final class Phrase
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(
        public readonly string $key,
        public readonly array $replace = [],
    ) {}

    /**
     * The text in the given language, or the key itself where the catalogue has none —
     * visible in the delivered message rather than silently blank, the same rule the
     * template renderer applies to a missing placeholder.
     */
    public function in(string $locale): string
    {
        $text = __($this->key, $this->replace, $locale);

        return is_string($text) ? $text : $this->key;
    }
}
