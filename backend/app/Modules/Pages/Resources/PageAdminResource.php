<?php

declare(strict_types=1);

namespace App\Modules\Pages\Resources;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Core\Seo\SeoFields;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Pages\Models\Page;
use App\Modules\Pages\Models\PageTranslation;
use App\Modules\Pages\Services\PageContent;
use App\Modules\Pages\Services\PageReader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A page as its editor needs it: what is the same in every language, each language's text as
 * written, and each language's progress (ADR 0055 §8).
 *
 * `translations` holds only what has been written. A language with nothing is absent from it
 * and marked not translated in `progress`; nothing is filled in from another language.
 *
 * @property-read Page $resource
 */
class PageAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $page = $this->resource;
        $locales = app(ContentLocales::class);
        $seo = app(SeoMetaStore::class)->all($page);
        $default = $locales->default();

        $translations = [];

        foreach ($locales->ordered($page->translations->pluck('locale')->all()) as $locale) {
            /** @var PageTranslation $translation */
            $translation = $page->translationIn($locale);

            $translations[$locale] = [
                'locale' => $locale,
                'title' => $translation->title,
                'slug' => $translation->slug,
                'summary' => $translation->summary,
                'body' => $translation->body,
                'updated_at' => $translation->updated_at?->toIso8601String(),
            ];
        }

        $progress = [];

        foreach ($locales->known() as $locale) {
            $progress[$locale] = PageContent::progress($page->translationIn($locale))->toArray();
        }

        $seoByLocale = [];

        foreach ($seo as $locale => $fields) {
            $seoByLocale[$locale] = $fields->toArray();
        }

        return [
            'id' => $page->id,
            'status' => $page->status->value,
            'status_label' => $page->status->label(),
            'published_at' => $page->published_at?->toIso8601String(),
            'sort_order' => $page->sort_order,
            'default_locale' => $default,
            /** The default language's title, for lists. Null when it has none. */
            'title' => $page->translationIn($default)?->getAttribute('title'),
            /** @var array<string, array{locale: string, title: string|null, slug: string|null, summary: string|null, body: string|null, updated_at: string|null}> */
            'translations' => (object) $translations,
            /** @var array<string, array{title: string|null, description: string|null, robots: string|null, canonical_url: string|null, og_title: string|null, og_description: string|null, og_media_id: string|null}> */
            'seo' => (object) $seoByLocale,
            /**
             * Every language the platform knows, and how far this page's translation has got in it.
             *
             * @var array<string, array{filled: int, total: int, complete: bool}>
             */
            'progress' => (object) $progress,
            /** Whether the default-language translation is complete, which publishing needs. */
            'publishable' => PageContent::isComplete($page->translationIn($default)),
            /**
             * The languages the page is public in right now: live, served and complete.
             *
             * @var list<string>
             */
            'available_locales' => $page->isLive() ? app(PageReader::class)->availableLocales($page) : [],
            'created_at' => $page->created_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Kept for symmetry with the SEO fields a client may send.
     *
     * @return list<string>
     */
    public static function seoFields(): array
    {
        return SeoFields::FIELDS;
    }
}
