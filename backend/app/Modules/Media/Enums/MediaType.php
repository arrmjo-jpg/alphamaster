<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * The broad kind of a file, derived from its detected content type.
 */
enum MediaType: string
{
    use HasDisplayLabel;

    case IMAGE = 'image';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case DOCUMENT = 'document';

    /**
     * Classify from a detected MIME type. Detected, never client supplied.
     */
    public static function fromMimeType(string $mime): self
    {
        return match (true) {
            str_starts_with($mime, 'image/') => self::IMAGE,
            str_starts_with($mime, 'video/') => self::VIDEO,
            str_starts_with($mime, 'audio/') => self::AUDIO,
            default => self::DOCUMENT,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
