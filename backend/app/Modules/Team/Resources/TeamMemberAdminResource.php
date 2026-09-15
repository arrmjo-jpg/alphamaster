<?php

declare(strict_types=1);

namespace App\Modules\Team\Resources;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Core\Contracts\MediaReferenceContract;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Team\Models\TeamMember;
use App\Modules\Team\Models\TeamMemberTranslation;
use App\Modules\Team\Services\TeamMemberContent;
use App\Modules\Team\Services\TeamReader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A team member as their editor needs them (ADR 0055 §8). Only what has been written appears
 * in `translations`; every known language's progress is in `progress`.
 *
 * @property-read TeamMember $resource
 */
class TeamMemberAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $member = $this->resource;
        $locales = app(ContentLocales::class);
        $default = $locales->default();

        $translations = [];

        foreach ($locales->ordered($member->translations->pluck('locale')->all()) as $locale) {
            /** @var TeamMemberTranslation $translation */
            $translation = $member->translationIn($locale);

            $translations[$locale] = [
                'locale' => $locale,
                'name' => $translation->name,
                'position' => $translation->position,
                'bio' => $translation->bio,
                'slug' => $translation->slug,
                'updated_at' => $translation->updated_at?->toIso8601String(),
            ];
        }

        $progress = [];

        foreach ($locales->known() as $locale) {
            $progress[$locale] = TeamMemberContent::progress($member->translationIn($locale))->toArray();
        }

        $seo = [];

        foreach (app(SeoMetaStore::class)->all($member) as $locale => $fields) {
            $seo[$locale] = $fields->toArray();
        }

        $avatar = $member->avatar_media_id === null ? null : app(MediaReferenceContract::class)->publicImage($member->avatar_media_id);

        return [
            'id' => $member->id,
            'is_active' => $member->is_active,
            'sort_order' => $member->sort_order,
            /** @var array{id: string, url: string, mime_type: string, width: int|null, height: int|null}|null */
            'avatar' => $avatar?->toArray(),
            'avatar_media_id' => $member->avatar_media_id,
            /** @var array<string, string> */
            'social_links' => (object) ($member->social_links ?? []),
            'default_locale' => $default,
            'name' => $member->translationIn($default)?->getAttribute('name'),
            /** @var array<string, array{locale: string, name: string|null, position: string|null, bio: string|null, slug: string|null, updated_at: string|null}> */
            'translations' => (object) $translations,
            /** @var array<string, array{title: string|null, description: string|null, robots: string|null, canonical_url: string|null, og_title: string|null, og_description: string|null, og_media_id: string|null}> */
            'seo' => (object) $seo,
            /** @var array<string, array{filled: int, total: int, complete: bool}> */
            'progress' => (object) $progress,
            /** Whether the default-language profile is complete, which activating needs. */
            'activatable' => TeamMemberContent::isComplete($member->translationIn($default)),
            /** @var list<string> */
            'available_locales' => $member->is_active ? app(TeamReader::class)->availableLocales($member) : [],
            'created_at' => $member->created_at?->toIso8601String(),
            'updated_at' => $member->updated_at?->toIso8601String(),
        ];
    }
}
