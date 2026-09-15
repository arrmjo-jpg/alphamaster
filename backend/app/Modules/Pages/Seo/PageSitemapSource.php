<?php

declare(strict_types=1);

namespace App\Modules\Pages\Seo;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Core\Contracts\PublicUrlContract;
use App\Modules\Core\Contracts\SiteSeoDefaultsContract;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Core\Seo\Sitemap\SitemapEntry;
use App\Modules\Core\Seo\Sitemap\SitemapSource;
use App\Modules\Pages\Models\Page;
use App\Modules\Pages\Services\PageContent;

/**
 * Pages in the sitemap (ADR 0058 §2): live pages, in each served language their translation
 * is complete in. A draft, an archived page and a scheduled page are not live and are not read.
 */
final class PageSitemapSource implements SitemapSource
{
    public function __construct(
        private readonly ContentLocales $locales,
        private readonly PublicUrlContract $urls,
        private readonly SeoMetaStore $seo,
        private readonly SiteSeoDefaultsContract $defaults,
    ) {}

    public function key(): string
    {
        return 'pages';
    }

    public function count(): int
    {
        $count = 0;

        foreach ($this->live() as $page) {
            $count += count($this->available($page));
        }

        return $count;
    }

    public function entries(): iterable
    {
        $default = $this->locales->default();

        foreach ($this->live() as $page) {
            $addresses = [];

            foreach ($this->available($page) as $locale) {
                $url = $this->urls->url('pages', $locale, ['slug' => (string) $page->translationIn($locale)?->getAttribute('slug')]);

                if ($url !== null) {
                    $addresses[$locale] = $url;
                }
            }

            foreach ($addresses as $locale => $url) {
                $meta = $this->seo->for($page, $locale);

                yield new SitemapEntry(
                    url: $url,
                    lastModified: $page->updated_at,
                    alternates: $addresses,
                    defaultUrl: $addresses[$default] ?? null,
                    robots: $meta->robots ?? $this->defaults->robots(),
                    canonical: $meta?->canonicalUrl,
                );
            }
        }
    }

    /**
     * @return iterable<Page>
     */
    private function live(): iterable
    {
        return Page::query()->live()->with('translations')->lazyById(500);
    }

    /**
     * @return list<string>
     */
    private function available(Page $page): array
    {
        return array_values(array_filter(
            $this->locales->served(),
            static fn (string $locale): bool => PageContent::isComplete($page->translationIn($locale)),
        ));
    }
}
