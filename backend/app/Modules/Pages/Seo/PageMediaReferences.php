<?php

declare(strict_types=1);

namespace App\Modules\Pages\Seo;

use App\Modules\Core\Delivery\EdgeCacheTag;
use App\Modules\Core\Media\MediaReferencer;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Pages\Models\Page;

/**
 * The pages that show a file: those whose sharing image, in any language, is it (ADR 0058 §7).
 */
final class PageMediaReferences implements MediaReferencer
{
    public function __construct(private readonly SeoMetaStore $seo) {}

    public function edgeTagsReferencing(string $mediaId): array
    {
        return array_map(
            static fn (string $id): string => EdgeCacheTag::for('pages', $id),
            $this->seo->ownersReferencingMedia($mediaId, (new Page)->getMorphClass()),
        );
    }
}
