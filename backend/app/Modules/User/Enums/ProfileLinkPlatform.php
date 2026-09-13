<?php

declare(strict_types=1);

namespace App\Modules\User\Enums;

/**
 * What a profile link points at, so a client can label and style it (ADR 0051 §4).
 *
 * A closed list rather than free text: a client renders an icon per platform, and a
 * value it does not recognise would render as nothing. `website` and `other` cover
 * everything else without a label the client has to guess at.
 */
enum ProfileLinkPlatform: string
{
    case WEBSITE = 'website';
    case FACEBOOK = 'facebook';
    case INSTAGRAM = 'instagram';
    case X = 'x';
    case LINKEDIN = 'linkedin';
    case YOUTUBE = 'youtube';
    case TIKTOK = 'tiktok';
    case SNAPCHAT = 'snapchat';
    case TELEGRAM = 'telegram';
    case WHATSAPP = 'whatsapp';
    case GITHUB = 'github';
    case OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $platform): string => $platform->value, self::cases());
    }
}
