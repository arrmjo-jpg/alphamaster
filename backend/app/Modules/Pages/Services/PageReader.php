<?php

declare(strict_types=1);

namespace App\Modules\Pages\Services;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Pages\Models\Page;
use App\Modules\Pages\Models\PageSlugHistory;
use App\Modules\Pages\Models\PageTranslation;
use Illuminate\Support\Collection;

/**
 * Pages as the public reads them (ADR 0055 §4, §5).
 *
 * A page is public in a language when it is live, the language is served, and its
 * translation there is complete. Nothing is read from another language.
 */
class PageReader
{
    public function __construct(private readonly ContentLocales $locales) {}

    /**
     * Live pages available in one language, in the operator's order.
     *
     * @return Collection<int, Page>
     */
    public function listIn(string $locale): Collection
    {
        return Page::query()
            ->live()
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->filter(static fn (Page $page): bool => PageContent::isComplete($page->translationIn($locale)))
            ->values();
    }

    public function lookup(string $slug, string $locale): PageLookup
    {
        /** @var PageTranslation|null $translation */
        $translation = PageTranslation::query()->where('locale', $locale)->where('slug', $slug)->first();

        if ($translation !== null) {
            $page = Page::query()->live()->with('translations')->find($translation->page_id);

            if ($page !== null) {
                return PageContent::isComplete($page->translationIn($locale))
                    ? PageLookup::found($page)
                    : PageLookup::unavailable($page, $this->availableLocales($page));
            }
        }

        // An address this page used to have in this language.
        /** @var PageSlugHistory|null $old */
        $old = PageSlugHistory::query()->where('locale', $locale)->where('slug', $slug)->first();

        if ($old !== null) {
            $page = Page::query()->live()->with('translations')->find($old->page_id);
            $current = $page?->translationIn($locale);

            if ($page !== null && PageContent::isComplete($current) && $current->slug !== $slug) {
                return PageLookup::redirect($page, (string) $current->slug);
            }
        }

        // Another language's address for a page. It redirects to this language's address when
        // the page exists here; otherwise it says which languages it does exist in. The content
        // itself is never taken from the other language.
        $elsewhere = PageTranslation::query()
            ->where('slug', $slug)
            ->where('locale', '!=', $locale)
            ->pluck('page_id');

        foreach ($elsewhere as $pageId) {
            $page = Page::query()->live()->with('translations')->find($pageId);

            if ($page === null) {
                continue;
            }

            $here = $page->translationIn($locale);

            return PageContent::isComplete($here)
                ? PageLookup::redirect($page, (string) $here->slug)
                : PageLookup::unavailable($page, $this->availableLocales($page));
        }

        return PageLookup::missing();
    }

    /**
     * The served languages this page may be read in, default first.
     *
     * @return list<string>
     */
    public function availableLocales(Page $page): array
    {
        return array_values(array_filter(
            $this->locales->served(),
            static fn (string $locale): bool => PageContent::isComplete($page->translationIn($locale)),
        ));
    }
}
