<?php

declare(strict_types=1);

namespace App\Modules\Pages\Resources;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Core\Contracts\PublicUrlContract;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Core\Seo\StructuredData\StructuredData;
use App\Modules\Pages\Models\Page;
use App\Modules\Pages\Models\PageTranslation;
use App\Modules\Pages\Services\PageReader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A page in one language, as a public client renders it (ADR 0055 §5, §7).
 *
 * Every text field is the requested language's own. `alternates` lists the other languages
 * the page may be read in, with their addresses, for `hreflang`.
 *
 * @property-read Page $resource
 */
class PublicPageResource extends JsonResource
{
    public function __construct(Page $resource, private readonly string $locale, private readonly bool $withBody = true)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $page = $this->resource;
        /** @var PageTranslation $translation */
        $translation = $page->translationIn($this->locale);
        $store = app(SeoMetaStore::class);
        $urls = app(PublicUrlContract::class);
        $url = $urls->url('pages', $this->locale, ['slug' => (string) $translation->slug]);
        $alternates = [];

        foreach (app(PageReader::class)->availableLocales($page) as $locale) {
            if ($locale !== $this->locale) {
                $slug = (string) $page->translationIn($locale)?->getAttribute('slug');
                $alternates[] = ['locale' => $locale, 'slug' => $slug, 'url' => $urls->url('pages', $locale, ['slug' => $slug])];
            }
        }

        $data = [
            'id' => $page->id,
            'locale' => $this->locale,
            'direction' => app(ContentLocales::class)->direction($this->locale),
            'slug' => (string) $translation->slug,
            /** The page's public address in this language, or null when no public origin is configured. */
            'url' => $url,
            'title' => (string) $translation->title,
            'summary' => $translation->summary,
            'sort_order' => $page->sort_order,
            'published_at' => $page->published_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];

        if (! $this->withBody) {
            return $data;
        }

        $seo = $store->resolve($store->for($page, $this->locale), $this->locale, (string) $translation->title, $translation->summary, null, $url);

        return [
            ...$data,
            /** Sanitised HTML. */
            'body' => (string) $translation->body,
            /** @var array{title: string, description: string|null, robots: string, canonical_url: string|null, og_title: string, og_description: string|null, og_image_url: string|null, twitter_card: string} */
            'seo' => $seo->toArray(),
            /** @var list<array{locale: string, slug: string, url: string|null}> */
            'alternates' => $alternates,
            /**
             * JSON-LD for the page: the site's WebSite and Organization, and this WebPage.
             *
             * @var array{'@context': string, '@graph': list<array<string, mixed>>}|null
             */
            'structured_data' => app(StructuredData::class)->for('pages', $page, $this->locale, $seo, $url),
        ];
    }
}
