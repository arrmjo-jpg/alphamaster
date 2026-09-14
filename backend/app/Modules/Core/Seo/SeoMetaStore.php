<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

use App\Modules\Core\Contracts\MediaReferenceContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-locale SEO metadata for any model, and its resolution (ADR 0032, ADR 0055 §7).
 *
 * The store is one table keyed by the owning model and the locale. Resolution never leaves
 * the locale it was asked for: an unset SEO title becomes the content's own title in that
 * language, not the default language's SEO title.
 */
class SeoMetaStore
{
    public function __construct(private readonly MediaReferenceContract $media) {}

    public function for(Model $owner, string $locale): ?SeoFields
    {
        return $this->query($owner)->where('locale', $locale)->first()?->fields();
    }

    /**
     * Every locale's metadata for one model.
     *
     * @return array<string, SeoFields>
     */
    public function all(Model $owner): array
    {
        $all = [];

        foreach ($this->query($owner)->get() as $row) {
            /** @var SeoMeta $row */
            $all[$row->locale] = $row->fields();
        }

        return $all;
    }

    /**
     * Replace one locale's metadata. Empty metadata removes the row rather than keeping an
     * empty one, so "nothing set" has one representation.
     */
    public function put(Model $owner, string $locale, SeoFields $fields): void
    {
        if ($fields->isEmpty()) {
            $this->query($owner)->where('locale', $locale)->delete();

            return;
        }

        SeoMeta::query()->updateOrCreate(
            [
                'seoable_type' => $owner->getMorphClass(),
                'seoable_id' => (string) $owner->getKey(),
                'locale' => $locale,
            ],
            $fields->toArray(),
        );
    }

    public function forget(Model $owner): void
    {
        $this->query($owner)->delete();
    }

    /**
     * What a page in this locale presents to search engines and link previews.
     *
     * @param  string  $title  the content's own title in this locale
     * @param  string|null  $description  the content's own summary in this locale
     * @param  string|null  $imageUrl  the content's own image, if it has one
     */
    public function resolve(?SeoFields $meta, string $title, ?string $description, ?string $imageUrl = null): ResolvedSeo
    {
        $resolvedTitle = $meta->title ?? $title;
        $resolvedDescription = $meta->description ?? $description;
        $ogImage = $meta?->ogMediaId !== null ? $this->media->publicImage($meta->ogMediaId)?->url : null;

        return new ResolvedSeo(
            title: $resolvedTitle,
            description: $resolvedDescription,
            robots: $meta?->robots,
            canonicalUrl: $meta?->canonicalUrl,
            ogTitle: $meta->ogTitle ?? $resolvedTitle,
            ogDescription: $meta->ogDescription ?? $resolvedDescription,
            ogImageUrl: $ogImage ?? $imageUrl,
        );
    }

    /**
     * @return Builder<SeoMeta>
     */
    private function query(Model $owner)
    {
        return SeoMeta::query()
            ->where('seoable_type', $owner->getMorphClass())
            ->where('seoable_id', (string) $owner->getKey());
    }
}
