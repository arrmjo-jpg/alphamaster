<?php

declare(strict_types=1);

namespace App\Modules\Pages\Services;

use App\Modules\Pages\Models\Page;

/**
 * What a public address resolves to (ADR 0055 §5, §6).
 */
final readonly class PageLookup
{
    public const FOUND = 'found';

    /** The address is an old or another language's slug for a page available here. */
    public const REDIRECT = 'redirect';

    /** The page exists and is live, and is not available in this language. */
    public const UNAVAILABLE = 'unavailable';

    public const MISSING = 'missing';

    /**
     * @param  list<string>  $availableLocales
     */
    private function __construct(
        public string $kind,
        public ?Page $page = null,
        public ?string $slug = null,
        public array $availableLocales = [],
    ) {}

    public static function found(Page $page): self
    {
        return new self(self::FOUND, $page);
    }

    public static function redirect(Page $page, string $slug): self
    {
        return new self(self::REDIRECT, $page, $slug);
    }

    /**
     * @param  list<string>  $availableLocales
     */
    public static function unavailable(Page $page, array $availableLocales): self
    {
        return new self(self::UNAVAILABLE, $page, null, $availableLocales);
    }

    public static function missing(): self
    {
        return new self(self::MISSING);
    }
}
