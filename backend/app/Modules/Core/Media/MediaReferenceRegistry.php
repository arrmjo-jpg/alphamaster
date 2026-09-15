<?php

declare(strict_types=1);

namespace App\Modules\Core\Media;

/**
 * Every module's media references, asked together (ADR 0058 §7, ADR 0052).
 */
final class MediaReferenceRegistry
{
    /** @var array<class-string<MediaReferencer>, MediaReferencer> */
    private array $referencers = [];

    public function register(MediaReferencer $referencer): void
    {
        $this->referencers[$referencer::class] = $referencer;
    }

    /**
     * The edge tags of every public response showing this file, across modules, once each.
     *
     * @return list<string>
     */
    public function edgeTagsFor(string $mediaId): array
    {
        $tags = [];

        foreach ($this->referencers as $referencer) {
            array_push($tags, ...$referencer->edgeTagsReferencing($mediaId));
        }

        return array_values(array_unique($tags));
    }
}
