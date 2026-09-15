<?php

declare(strict_types=1);

namespace App\Modules\Team\Seo;

use App\Modules\Core\Delivery\EdgeCacheTag;
use App\Modules\Core\Media\MediaReferencer;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Team\Models\TeamMember;

/**
 * The team responses that show a file (ADR 0058 §7): a member whose picture it is — shown in
 * the directory and the profile — and a member whose sharing image it is in any language.
 */
final class TeamMediaReferences implements MediaReferencer
{
    public function __construct(private readonly SeoMetaStore $seo) {}

    public function edgeTagsReferencing(string $mediaId): array
    {
        $pictured = TeamMember::query()->where('avatar_media_id', $mediaId)->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)->all();
        $shared = $this->seo->ownersReferencingMedia($mediaId, (new TeamMember)->getMorphClass());

        $tags = array_map(
            static fn (string $id): string => EdgeCacheTag::for('team', $id),
            array_values(array_unique([...$pictured, ...$shared])),
        );

        if ($pictured !== []) {
            $tags[] = EdgeCacheTag::for('team', 'list');
        }

        return $tags;
    }
}
