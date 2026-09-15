<?php

declare(strict_types=1);

namespace App\Modules\Team\Seo;

use App\Modules\Core\Contracts\MediaReferenceContract;
use App\Modules\Core\Seo\ResolvedSeo;
use App\Modules\Core\Seo\StructuredData\StructuredDataGenerator;
use App\Modules\Team\Models\TeamMember;
use Illuminate\Database\Eloquent\Model;

/**
 * A team member as a schema.org `Person` (ADR 0058 §5).
 */
final class PersonSchema implements StructuredDataGenerator
{
    public function __construct(private readonly MediaReferenceContract $media) {}

    public function key(): string
    {
        return 'team';
    }

    public function node(Model $owner, string $locale, ResolvedSeo $seo, ?string $url): array
    {
        $member = $owner instanceof TeamMember ? $owner : null;
        $translation = $member?->translationIn($locale);
        $avatar = $member?->avatar_media_id === null ? null : $this->media->publicImage($member->avatar_media_id);

        return [
            '@type' => 'Person',
            '@id' => $url === null ? null : $url.'#person',
            'url' => $seo->canonicalUrl ?? $url,
            'name' => $translation?->getAttribute('name'),
            'jobTitle' => $translation?->getAttribute('position'),
            'description' => $seo->description,
            'image' => $avatar->url ?? $seo->ogImageUrl,
            'sameAs' => array_values(array_filter($member->social_links ?? [], static fn (string $link): bool => $link !== '')),
        ];
    }
}
