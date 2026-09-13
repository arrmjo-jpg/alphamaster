<?php

declare(strict_types=1);

namespace App\Modules\Notification\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * Who an announcement is addressed to.
 *
 * A closed set rather than a query an operator composes, and small on purpose. These
 * are the two audiences the platform can describe without knowing what it is for:
 * everyone who can sign in, and the people who run it. Anything narrower — a
 * department, a cohort, a segment — is a property of an application built on this
 * foundation and not of the foundation (ADR 0033).
 *
 * Suspended accounts are in neither. A message to an account that cannot sign in is a
 * message nobody will read, and the in-app record is the channel this notification
 * defaults to.
 */
enum AnnouncementAudience: string
{
    use HasDisplayLabel;

    /** Every active account, administrators included. */
    case EVERYONE = 'everyone';

    /** Active administrative accounts only. */
    case ADMINISTRATORS = 'administrators';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
