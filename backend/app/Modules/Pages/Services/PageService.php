<?php

declare(strict_types=1);

namespace App\Modules\Pages\Services;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Core\Content\ContentRefusedException;
use App\Modules\Core\Content\HtmlSanitizer;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Core\Delivery\EdgeCacheTag;
use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Seo\SeoFields;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Core\Seo\Sitemap\SitemapRenderer;
use App\Modules\Core\Support\Slug;
use App\Modules\Pages\Enums\PageStatus;
use App\Modules\Pages\Models\Page;
use App\Modules\Pages\Models\PageSlugHistory;
use App\Modules\Pages\Models\PageTranslation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every change to a page goes through here (ADR 0055).
 *
 * One page, translated one language at a time. The rules that make publishing safe live
 * here and nowhere else:
 *
 * * a page is published only when its default-language translation is complete, and a
 *   published page cannot lose it;
 * * a translation in any other language may be missing or incomplete, and never affects the
 *   page's status;
 * * a published translation that changes its address keeps the old one, so it redirects;
 * * rich text is sanitised on the way in.
 *
 * Every committed change purges the page and the page list from the edge. What changed is
 * audited by field name, never by text.
 */
class PageService
{
    public function __construct(
        private readonly ContentLocales $locales,
        private readonly SeoMetaStore $seo,
        private readonly HtmlSanitizer $html,
        private readonly AuditRecorderContract $audit,
        private readonly EdgeCacheContract $edge,
    ) {}

    public function create(int $sortOrder, ?string $actorId): Page
    {
        return DB::transaction(function () use ($sortOrder, $actorId): Page {
            $page = Page::query()->create([
                'status' => PageStatus::DRAFT,
                'sort_order' => $sortOrder,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->audit->succeeded('page.created', $page->id, ['sort_order' => $sortOrder]);
            $this->invalidate($page);

            return $page;
        });
    }

    public function reorder(Page $page, int $sortOrder, ?string $actorId): Page
    {
        if ($page->sort_order === $sortOrder) {
            return $page;
        }

        return DB::transaction(function () use ($page, $sortOrder, $actorId): Page {
            $page->forceFill(['sort_order' => $sortOrder, 'updated_by' => $actorId])->save();

            $this->audit->succeeded('page.updated', $page->id, ['fields' => ['sort_order']]);
            $this->invalidate($page);

            return $page;
        });
    }

    /**
     * Write one language's text, address and SEO.
     *
     * Only the fields given are changed. A null or empty value clears a field. With no slug,
     * one is made from the title; a slug the operator typed must be well formed and unused in
     * that language.
     *
     * @param  array<string, string|null>  $values  any of title, slug, summary, body
     * @param  array<string, mixed>|null  $seo  the language's SEO fields, or null to leave them as they are
     *
     * @throws ContentRefusedException
     */
    public function writeTranslation(Page $page, string $locale, array $values, ?array $seo, ?string $actorId): ?PageTranslation
    {
        if (! $this->locales->isKnown($locale)) {
            throw ContentRefusedException::unknownLocale($locale);
        }

        $seoFields = $seo === null ? null : SeoFields::fromArray($seo);

        if ($seoFields !== null) {
            $this->seo->assertUsable($seoFields);
        }

        return DB::transaction(function () use ($page, $locale, $values, $seoFields, $actorId): ?PageTranslation {
            // Serialises writes to one page, so two editors cannot both claim a slug or both
            // pass the default-language check.
            Page::query()->whereKey($page->id)->lockForUpdate()->first();
            $page->refresh();

            /** @var PageTranslation|null $existing */
            $existing = PageTranslation::query()->where('page_id', $page->id)->where('locale', $locale)->first();
            $row = $existing ?? new PageTranslation(['page_id' => $page->id, 'locale' => $locale]);
            $before = $this->snapshot($row);

            foreach ($values as $field => $value) {
                if (! in_array($field, PageContent::FIELDS, true)) {
                    continue;
                }

                $row->setAttribute($field, $this->clean($field, $value));
            }

            $this->assignSlug($row, $page, $locale, array_key_exists('slug', $values));

            if ($page->status === PageStatus::PUBLISHED && $locale === $this->locales->default() && ! PageContent::isComplete($row)) {
                throw ContentRefusedException::defaultTranslationRequired($locale);
            }

            $changed = array_keys(array_diff_assoc($this->snapshot($row), $before));

            if ($changed !== []) {
                $this->keepOldAddress($page, $locale, $existing, $before['slug'], $row->slug);
                $this->persist($row, $existing);
            }

            $seoChanged = $seoFields !== null && $this->seo->write($page, $locale, $seoFields);

            if ($changed !== [] || $seoChanged) {
                $page->forceFill(['updated_by' => $actorId])->touch();

                $this->audit->succeeded('page.translation_updated', $page->id, [
                    'locale' => $locale,
                    'fields' => $changed,
                    'seo' => $seoChanged,
                ]);
                $this->invalidate($page);
            }

            $page->unsetRelation('translations');

            return $row->exists ? $row : null;
        });
    }

    /**
     * @throws ContentRefusedException when the default-language translation is not complete
     */
    public function publish(Page $page, ?Carbon $publishedAt, ?string $actorId): Page
    {
        return DB::transaction(function () use ($page, $publishedAt, $actorId): Page {
            Page::query()->whereKey($page->id)->lockForUpdate()->first();
            $page->refresh();

            $default = $this->locales->default();

            if (! PageContent::isComplete($page->translationIn($default))) {
                throw ContentRefusedException::defaultTranslationIncomplete($default);
            }

            $page->forceFill([
                'status' => PageStatus::PUBLISHED,
                'published_at' => $publishedAt ?? $page->published_at ?? now(),
                'updated_by' => $actorId,
            ])->save();

            $this->audit->succeeded('page.published', $page->id, [
                'published_at' => $page->published_at?->toIso8601String(),
            ]);
            $this->invalidate($page);

            return $page;
        });
    }

    public function unpublish(Page $page, ?string $actorId): Page
    {
        return $this->transition($page, PageStatus::DRAFT, 'page.unpublished', $actorId);
    }

    public function archive(Page $page, ?string $actorId): Page
    {
        return $this->transition($page, PageStatus::ARCHIVED, 'page.archived', $actorId);
    }

    public function delete(Page $page): void
    {
        DB::transaction(function () use ($page): void {
            // Its SEO goes with it, through HasSeoMeta.
            $page->delete();

            $this->audit->succeeded('page.deleted', $page->id);
            $this->invalidate($page);
        });
    }

    private function transition(Page $page, PageStatus $status, string $action, ?string $actorId): Page
    {
        if ($page->status === $status) {
            return $page;
        }

        return DB::transaction(function () use ($page, $status, $action, $actorId): Page {
            $page->forceFill(['status' => $status, 'updated_by' => $actorId])->save();

            $this->audit->succeeded($action, $page->id);
            $this->invalidate($page);

            return $page;
        });
    }

    private function clean(string $field, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($field === 'body' && $value !== '') {
            $value = $this->html->sanitize($value);

            return HtmlSanitizer::hasText($value) ? $value : null;
        }

        return $value === '' ? null : $value;
    }

    /**
     * @throws ContentRefusedException
     */
    private function assignSlug(PageTranslation $row, Page $page, string $locale, bool $explicit): void
    {
        $slug = $row->slug;

        if ($explicit && $slug !== null) {
            if (! Slug::isValid($slug)) {
                throw ContentRefusedException::slugInvalid($locale);
            }

            if ($this->slugTaken($locale, $slug, $page->id)) {
                throw ContentRefusedException::slugTaken($locale, $slug);
            }

            return;
        }

        // No address yet: made from the title, so a translation written without one — in the
        // workshop, say — still becomes reachable.
        if ($slug === null && is_string($row->title) && $row->title !== '') {
            $row->slug = $this->uniqueSlug($locale, Slug::make($row->title), $page->id);
        }
    }

    private function slugTaken(string $locale, string $slug, string $pageId): bool
    {
        return PageTranslation::query()
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->where('page_id', '!=', $pageId)
            ->exists();
    }

    private function uniqueSlug(string $locale, string $base, string $pageId): string
    {
        $candidate = $base;
        $suffix = 2;

        while ($this->slugTaken($locale, $candidate, $pageId)) {
            $tail = '-'.$suffix++;
            $candidate = rtrim(mb_substr($base, 0, Slug::MAX_LENGTH - mb_strlen($tail)), '-').$tail;
        }

        return $candidate;
    }

    /**
     * A published page that changes address in a language keeps the old one, so it redirects
     * (ADR 0055 §6). An address a live translation now uses is never also a redirect.
     */
    private function keepOldAddress(Page $page, string $locale, ?PageTranslation $existing, ?string $oldSlug, ?string $newSlug): void
    {
        if ($existing !== null && $oldSlug !== null && $oldSlug !== $newSlug && $page->status === PageStatus::PUBLISHED) {
            PageSlugHistory::query()->updateOrCreate(
                ['locale' => $locale, 'slug' => $oldSlug],
                ['page_id' => $page->id],
            );
        }

        if ($newSlug !== null) {
            PageSlugHistory::query()->where('locale', $locale)->where('slug', $newSlug)->delete();
        }
    }

    private function persist(PageTranslation $row, ?PageTranslation $existing): void
    {
        $empty = array_filter($this->snapshot($row), static fn (string $value): bool => $value !== '') === [];

        if (! $empty) {
            $row->save();

            return;
        }

        // Every field cleared: the language has no translation, which is a missing row rather
        // than a row of nulls.
        $existing?->delete();
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(PageTranslation $row): array
    {
        $values = [];

        foreach (PageContent::FIELDS as $field) {
            $values[$field] = (string) $row->getAttribute($field);
        }

        return $values;
    }

    private function invalidate(Page $page): void
    {
        // The page, the list, and the sitemap that lists it (ADR 0058 §2).
        $tags = [
            EdgeCacheTag::for('pages', $page->id),
            EdgeCacheTag::for('pages', 'list'),
            SitemapRenderer::sourceTag('pages'),
            SitemapRenderer::indexTag(),
        ];

        DB::afterCommit(fn () => rescue(fn () => $this->edge->invalidate(EdgeInvalidation::tags($tags), 'pages.changed')));
    }
}
