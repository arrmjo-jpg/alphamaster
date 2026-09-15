<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

/**
 * A translation source whose text is written in a language of its own (ADR 0049).
 *
 * Content is translated from the platform's default language, whatever that is. The interface
 * is not: its catalogue is written in one language by the people who write the code, and every
 * other language — the default included — is translated from that one. A source that implements
 * this is translated from the language it names; every other source keeps the default.
 */
interface DeclaresSourceLocale
{
    public function sourceLocale(): string;
}
