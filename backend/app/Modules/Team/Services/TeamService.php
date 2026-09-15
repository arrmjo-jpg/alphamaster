<?php

declare(strict_types=1);

namespace App\Modules\Team\Services;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Core\Content\ContentRefusedException;
use App\Modules\Core\Content\HtmlSanitizer;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Core\Contracts\MediaReferenceContract;
use App\Modules\Core\Delivery\EdgeCacheTag;
use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Seo\SeoFields;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Core\Support\Slug;
use App\Modules\Team\Models\TeamMember;
use App\Modules\Team\Models\TeamMemberSlugHistory;
use App\Modules\Team\Models\TeamMemberTranslation;
use Illuminate\Support\Facades\DB;

/**
 * Every change to the team directory goes through here (ADR 0055).
 *
 * The same rules as pages, with "active" in place of "published": a member is shown only when
 * their default-language profile is complete, and an active member cannot lose it. Profiles in
 * other languages may be missing and never hide the member elsewhere.
 */
class TeamService
{
    public function __construct(
        private readonly ContentLocales $locales,
        private readonly SeoMetaStore $seo,
        private readonly HtmlSanitizer $html,
        private readonly MediaReferenceContract $media,
        private readonly AuditRecorderContract $audit,
        private readonly EdgeCacheContract $edge,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  sort_order, avatar_media_id, social_links
     *
     * @throws ContentRefusedException
     */
    public function create(array $attributes, ?string $actorId): TeamMember
    {
        $this->assertAvatar($attributes);

        return DB::transaction(function () use ($attributes, $actorId): TeamMember {
            $member = TeamMember::query()->create([
                'is_active' => false,
                'sort_order' => (int) ($attributes['sort_order'] ?? 0),
                'avatar_media_id' => $attributes['avatar_media_id'] ?? null,
                'social_links' => $this->socialLinks($attributes['social_links'] ?? null),
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->audit->succeeded('team_member.created', $member->id);
            $this->invalidate($member);

            return $member;
        });
    }

    /**
     * Change what is the same in every language. Activating needs a complete default-language
     * profile.
     *
     * @param  array<string, mixed>  $attributes  any of is_active, sort_order, avatar_media_id, social_links
     *
     * @throws ContentRefusedException
     */
    public function update(TeamMember $member, array $attributes, ?string $actorId): TeamMember
    {
        $this->assertAvatar($attributes);

        return DB::transaction(function () use ($member, $attributes, $actorId): TeamMember {
            TeamMember::query()->whereKey($member->id)->lockForUpdate()->first();
            $member->refresh();

            $fill = [];

            foreach (['is_active', 'sort_order', 'avatar_media_id'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $fill[$field] = $attributes[$field];
                }
            }

            if (array_key_exists('social_links', $attributes)) {
                $fill['social_links'] = $this->socialLinks($attributes['social_links']);
            }

            if (($fill['is_active'] ?? false) === true && ! $member->is_active) {
                $default = $this->locales->default();

                if (! TeamMemberContent::isComplete($member->translationIn($default))) {
                    throw ContentRefusedException::defaultTranslationIncomplete($default);
                }
            }

            $member->fill($fill);
            $changed = array_keys($member->getDirty());

            if ($changed === []) {
                return $member;
            }

            $member->forceFill(['updated_by' => $actorId])->save();

            $this->audit->succeeded('team_member.updated', $member->id, ['fields' => $changed]);
            $this->invalidate($member);

            return $member;
        });
    }

    /**
     * Write one language's profile and SEO.
     *
     * @param  array<string, string|null>  $values  any of name, position, bio, slug
     * @param  array<string, mixed>|null  $seo
     *
     * @throws ContentRefusedException
     */
    public function writeTranslation(TeamMember $member, string $locale, array $values, ?array $seo, ?string $actorId): ?TeamMemberTranslation
    {
        if (! $this->locales->isKnown($locale)) {
            throw ContentRefusedException::unknownLocale($locale);
        }

        $seoFields = $seo === null ? null : SeoFields::fromArray($seo);

        if ($seoFields !== null) {
            $this->seo->assertUsable($seoFields);
        }

        return DB::transaction(function () use ($member, $locale, $values, $seoFields, $actorId): ?TeamMemberTranslation {
            TeamMember::query()->whereKey($member->id)->lockForUpdate()->first();
            $member->refresh();

            /** @var TeamMemberTranslation|null $existing */
            $existing = TeamMemberTranslation::query()->where('team_member_id', $member->id)->where('locale', $locale)->first();
            $row = $existing ?? new TeamMemberTranslation(['team_member_id' => $member->id, 'locale' => $locale]);
            $before = $this->snapshot($row);

            foreach ($values as $field => $value) {
                if (in_array($field, TeamMemberContent::FIELDS, true)) {
                    $row->setAttribute($field, $this->clean($field, $value));
                }
            }

            $this->assignSlug($row, $member, $locale, array_key_exists('slug', $values));

            if ($member->is_active && $locale === $this->locales->default() && ! TeamMemberContent::isComplete($row)) {
                throw ContentRefusedException::defaultTranslationRequired($locale);
            }

            $changed = array_keys(array_diff_assoc($this->snapshot($row), $before));

            if ($changed !== []) {
                $this->keepOldAddress($member, $locale, $existing, $before['slug'], $row->slug);
                $this->persist($row, $existing);
            }

            $seoChanged = $seoFields !== null && $this->seo->write($member, $locale, $seoFields);

            if ($changed !== [] || $seoChanged) {
                $member->forceFill(['updated_by' => $actorId])->touch();

                $this->audit->succeeded('team_member.translation_updated', $member->id, [
                    'locale' => $locale,
                    'fields' => $changed,
                    'seo' => $seoChanged,
                ]);
                $this->invalidate($member);
            }

            $member->unsetRelation('translations');

            return $row->exists ? $row : null;
        });
    }

    public function delete(TeamMember $member): void
    {
        DB::transaction(function () use ($member): void {
            // Their SEO goes with them, through HasSeoMeta.
            $member->delete();

            $this->audit->succeeded('team_member.deleted', $member->id);
            $this->invalidate($member);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ContentRefusedException
     */
    private function assertAvatar(array $attributes): void
    {
        $avatar = $attributes['avatar_media_id'] ?? null;

        if (is_string($avatar) && $this->media->publicImage($avatar) === null) {
            throw ContentRefusedException::imageUnavailable('avatar_media_id');
        }
    }

    /**
     * Known networks only, trimmed, with empty ones dropped.
     *
     * @return array<string, string>|null
     */
    private function socialLinks(mixed $links): ?array
    {
        if (! is_array($links)) {
            return null;
        }

        $clean = [];

        foreach (TeamMemberContent::SOCIAL_NETWORKS as $network) {
            $value = $links[$network] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $clean[$network] = trim($value);
            }
        }

        return $clean === [] ? null : $clean;
    }

    private function clean(string $field, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($field === 'bio' && $value !== '') {
            $value = $this->html->sanitize($value);

            return HtmlSanitizer::hasText($value) ? $value : null;
        }

        return $value === '' ? null : $value;
    }

    /**
     * @throws ContentRefusedException
     */
    private function assignSlug(TeamMemberTranslation $row, TeamMember $member, string $locale, bool $explicit): void
    {
        $slug = $row->slug;

        if ($explicit && $slug !== null) {
            if (! Slug::isValid($slug)) {
                throw ContentRefusedException::slugInvalid($locale);
            }

            if ($this->slugTaken($locale, $slug, $member->id)) {
                throw ContentRefusedException::slugTaken($locale, $slug);
            }

            return;
        }

        if ($slug === null && is_string($row->name) && $row->name !== '') {
            $row->slug = $this->uniqueSlug($locale, Slug::make($row->name), $member->id);
        }
    }

    private function slugTaken(string $locale, string $slug, string $memberId): bool
    {
        return TeamMemberTranslation::query()
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->where('team_member_id', '!=', $memberId)
            ->exists();
    }

    private function uniqueSlug(string $locale, string $base, string $memberId): string
    {
        $candidate = $base;
        $suffix = 2;

        while ($this->slugTaken($locale, $candidate, $memberId)) {
            $tail = '-'.$suffix++;
            $candidate = rtrim(mb_substr($base, 0, Slug::MAX_LENGTH - mb_strlen($tail)), '-').$tail;
        }

        return $candidate;
    }

    private function keepOldAddress(TeamMember $member, string $locale, ?TeamMemberTranslation $existing, ?string $oldSlug, ?string $newSlug): void
    {
        if ($existing !== null && $oldSlug !== null && $oldSlug !== $newSlug && $member->is_active) {
            TeamMemberSlugHistory::query()->updateOrCreate(
                ['locale' => $locale, 'slug' => $oldSlug],
                ['team_member_id' => $member->id],
            );
        }

        if ($newSlug !== null) {
            TeamMemberSlugHistory::query()->where('locale', $locale)->where('slug', $newSlug)->delete();
        }
    }

    private function persist(TeamMemberTranslation $row, ?TeamMemberTranslation $existing): void
    {
        $empty = array_filter($this->snapshot($row), static fn (string $value): bool => $value !== '') === [];

        if (! $empty) {
            $row->save();

            return;
        }

        $existing?->delete();
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(TeamMemberTranslation $row): array
    {
        $values = [];

        foreach (TeamMemberContent::FIELDS as $field) {
            $values[$field] = (string) $row->getAttribute($field);
        }

        return $values;
    }

    private function invalidate(TeamMember $member): void
    {
        $tags = [EdgeCacheTag::for('team', $member->id), EdgeCacheTag::for('team', 'list')];

        DB::afterCommit(fn () => rescue(fn () => $this->edge->invalidate(EdgeInvalidation::tags($tags), 'team.changed')));
    }
}
