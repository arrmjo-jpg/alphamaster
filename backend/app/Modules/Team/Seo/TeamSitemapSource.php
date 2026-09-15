<?php

declare(strict_types=1);

namespace App\Modules\Team\Seo;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Core\Contracts\PublicUrlContract;
use App\Modules\Core\Contracts\SiteSeoDefaultsContract;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Core\Seo\Sitemap\SitemapEntry;
use App\Modules\Core\Seo\Sitemap\SitemapSource;
use App\Modules\Team\Models\TeamMember;
use App\Modules\Team\Services\TeamMemberContent;

/**
 * Team profiles in the sitemap (ADR 0058 §2): active members, in each served language their
 * profile is complete in.
 */
final class TeamSitemapSource implements SitemapSource
{
    public function __construct(
        private readonly ContentLocales $locales,
        private readonly PublicUrlContract $urls,
        private readonly SeoMetaStore $seo,
        private readonly SiteSeoDefaultsContract $defaults,
    ) {}

    public function key(): string
    {
        return 'team';
    }

    public function count(): int
    {
        $count = 0;

        foreach ($this->active() as $member) {
            $count += count($this->available($member));
        }

        return $count;
    }

    public function entries(): iterable
    {
        $default = $this->locales->default();

        foreach ($this->active() as $member) {
            $addresses = [];

            foreach ($this->available($member) as $locale) {
                $url = $this->urls->url('team', $locale, ['slug' => (string) $member->translationIn($locale)?->getAttribute('slug')]);

                if ($url !== null) {
                    $addresses[$locale] = $url;
                }
            }

            foreach ($addresses as $locale => $url) {
                $meta = $this->seo->for($member, $locale);

                yield new SitemapEntry(
                    url: $url,
                    lastModified: $member->updated_at,
                    alternates: $addresses,
                    defaultUrl: $addresses[$default] ?? null,
                    robots: $meta->robots ?? $this->defaults->robots(),
                    canonical: $meta?->canonicalUrl,
                );
            }
        }
    }

    /**
     * @return iterable<TeamMember>
     */
    private function active(): iterable
    {
        return TeamMember::query()->where('is_active', true)->with('translations')->lazyById(500);
    }

    /**
     * @return list<string>
     */
    private function available(TeamMember $member): array
    {
        return array_values(array_filter(
            $this->locales->served(),
            static fn (string $locale): bool => TeamMemberContent::isComplete($member->translationIn($locale)),
        ));
    }
}
