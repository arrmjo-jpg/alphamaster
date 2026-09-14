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
     * Whether a file of this type plays for a length of time, and so has a duration to read.
     */
    public function hasDuration(): bool
    {
        return $this === self::VIDEO || $this === self::AUDIO;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
