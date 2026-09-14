<?php

declare(strict_types=1);

namespace App\Modules\Team\Resources;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Core\Contracts\MediaReferenceContract;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Team\Models\TeamMember;
use App\Modules\Team\Models\TeamMemberTranslation;
use App\Modules\Team\Services\TeamReader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A team member's profile in one language, as a public client renders it (ADR 0055 §5, §7).
 *
 * @property-read TeamMember $resource
 */
class PublicTeamMemberResource extends JsonResource
{
    public function __construct(TeamMember $resource, private readonly string $locale, private readonly bool $withProfile = true)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $member = $this->resource;
        /** @var TeamMemberTranslation $translation */
        $translation = $member->translationIn($this->locale);
        $avatar = $member->avatar_media_id === null ? null : app(MediaReferenceContract::class)->publicImage($member->avatar_media_id);

        $data = [
            'id' => $member->id,
            'locale' => $this->locale,
            'direction' => app(ContentLocales::class)->direction($this->locale),
            'slug' => (string) $translation->slug,
            'name' => (string) $translation->name,
            'position' => (string) $translation->position,
            /** @var array{id: string, url: string, mime_type: string, width: int|null, height: int|null}|null */
            'avatar' => $avatar?->toArray(),
            'sort_order' => $member->sort_order,
        ];

        if (! $this->withProfile) {
            return $data;
        }

        $alternates = [];

        foreach (app(TeamReader::class)->availableLocales($member) as $locale) {
            if ($locale !== $this->locale) {
                $alternates[] = ['locale' => $locale, 'slug' => (string) $member->translationIn($locale)?->getAttribute('slug')];
            }
        }

        $store = app(SeoMetaStore::class);

        return [
            ...$data,
            /** Sanitised HTML, or null. */
            'bio' => $translation->bio,
            /** @var array<string, string> */
            'social_links' => (object) ($member->social_links ?? []),
            /** @var array{title: string, description: string|null, robots: string|null, canonical_url: string|null, og_title: string, og_description: string|null, og_image_url: string|null} */
            'seo' => $store->resolve($store->for($member, $this->locale), (string) $translation->name, $translation->position, $avatar?->url)->toArray(),
            /** @var list<array{locale: string, slug: string}> */
            'alternates' => $alternates,
        ];
    }
}
