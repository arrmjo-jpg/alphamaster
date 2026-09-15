<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo\Sitemap;

use DateTimeInterface;

/**
 * One address in one language, with the addresses of the same content in its other languages.
 */
final readonly class SitemapEntry
{
    /**
     * @param  string  $url  the absolute address
     * @param  array<string, string>  $alternates  locale => absolute address, including this one
     * @param  string|null  $defaultUrl  the default language's address, for `x-default`
     * @param  string|null  $robots  the resolved robots value for this language
     * @param  string|null  $canonical  an operator's canonical override, when there is one
     */
    public function __construct(
        public string $url,
        public ?DateTimeInterface $lastModified = null,
        public array $alternates = [],
        public ?string $defaultUrl = null,
        public ?string $robots = null,
        public ?string $canonical = null,
    ) {}

    /**
     * Whether the address belongs in a sitemap: not `noindex`, and not an address whose
     * canonical is somewhere else.
     */
    public function isIndexable(): bool
    {
        if ($this->robots !== null && str_contains($this->robots, 'noindex')) {
            return false;
        }

        return $this->canonical === null || $this->canonical === $this->url;
    }
}
