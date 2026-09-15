<?php

declare(strict_types=1);

namespace App\Modules\Core\Media;

/**
 * What a content module may know about a media file it references: where it is served
 * and what shape it has. No disk, path or storage detail.
 */
final readonly class MediaReference
{
    public function __construct(
        public string $id,
        public string $url,
        public string $mimeType,
        public ?int $width = null,
        public ?int $height = null,
    ) {}

    /**
     * @return array{id: string, url: string, mime_type: string, width: int|null, height: int|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'mime_type' => $this->mimeType,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
