<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

/**
 * Whether a file is reachable without authorization.
 *
 * Deliberately only two values. Who is entitled to a private file is a business
 * question that Media cannot answer — owner, team member, judge, subscriber — so it
 * is delegated to a MediaAccessPolicy the attaching module supplies. Media knows
 * whether authorization is required, never who satisfies it.
 */
enum MediaVisibility: string
{
    case PUBLIC = 'public';
    case PRIVATE = 'private';

    public function requiresAuthorization(): bool
    {
        return $this === self::PRIVATE;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
