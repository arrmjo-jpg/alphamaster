<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

use App\Modules\Core\Content\ContentRefusedException;
use App\Modules\Core\Contracts\MediaReferenceContract;
use App\Modules\Core\Contracts\SiteSeoDefaultsContract;
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
    public function __construct(
        private readonly MediaReferenceContract $media,
        private readonly SiteSeoDefaultsContract $defaults,
    ) {}

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

    /**
     * Refuse a sharing image that is not a public image ready to serve (ADR 0055 §10).
     *
     * Separate from `write` so an owner can refuse before it takes a lock.
     *
     * @throws ContentRefusedException
     */
    public function assertUsable(SeoFields $fields): void
    {
        if ($fields->ogMediaId !== null && $this->media->publicImage($fields->ogMediaId) === null) {
            throw ContentRefusedException::imageUnavailable('seo.og_media_id');
        }
    }

    /**
     * Replace one language's metadata if it changed, and answer whether it did.
     *
     * The one write path for every module: the image rule is applied here, and the answer lets
     * the owner decide what to audit and which edge tags to purge without comparing fields
     * itself.
     *
     * @throws ContentRefusedException
     */
    public function write(Model $owner, string $locale, SeoFields $fields): bool
    {
        $this->assertUsable($fields);

        $previous = $this->for($owner, $locale) ?? new SeoFields;

        if ($previous->toArray() === $fields->toArray()) {
            return false;
        }

        $this->put($owner, $locale, $fields);

        return true;
    }

    public function forget(Model $owner): void
    {
        $this->query($owner)->delete();
    }

    /**
     * What content in one language presents to search engines and link previews (ADR 0058 §4).
     *
     * Every step stays in the language asked for:
     *
     *     title        SEO title → the content's title → the site's name in this language
     *     description  SEO description → the content's summary → the site's description here
     *     og image     SEO image → the content's image → the site's default sharing image
     *     robots       SEO robots → the site's policy (index,follow when none is set)
     *     canonical    SEO canonical → the content's own address in this language
     *
     * @param  string  $title  the content's own title in this locale
     * @param  string|null  $description  the content's own summary in this locale
     * @param  string|null  $imageUrl  the content's own image, if it has one
     * @param  string|null  $url  the content's own public address in this locale
     */
    public function resolve(?SeoFields $meta, string $locale, string $title, ?string $description, ?string $imageUrl = null, ?string $url = null): ResolvedSeo
    {
        $resolvedTitle = $meta->title ?? (trim($title) !== '' ? $title : ($this->defaults->title($locale) ?? $title));
        $resolvedDescription = $meta->description
            ?? ($description !== null && trim($description) !== '' ? $description : $this->defaults->description($locale));
        $ogImage = ($meta?->ogMediaId !== null ? $this->media->publicImage($meta->ogMediaId)?->url : null)
            ?? $imageUrl
            ?? $this->defaults->imageUrl();

        return new ResolvedSeo(
            title: $resolvedTitle,
            description: $resolvedDescription,
            robots: $meta->robots ?? $this->defaults->robots(),
            canonicalUrl: $meta->canonicalUrl ?? $url,
            ogTitle: $meta->ogTitle ?? $resolvedTitle,
            ogDescription: $meta->ogDescription ?? $resolvedDescription,
            ogImageUrl: $ogImage,
            twitterCard: $ogImage !== null ? 'summary_large_image' : 'summary',
        );
    }

    /**
     * The owners of one type whose SEO in any language uses this file as its sharing image.
     *
     * @return list<string>
     */
    public function ownersReferencingMedia(string $mediaId, string $morphClass): array
    {
        return SeoMeta::query()
            ->where('og_media_id', $mediaId)
            ->where('seoable_type', $morphClass)
            ->distinct()
            ->pluck('seoable_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
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
