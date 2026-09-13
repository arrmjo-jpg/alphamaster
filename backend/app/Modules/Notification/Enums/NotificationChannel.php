<?php

declare(strict_types=1);

namespace App\Modules\Notification\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * A route a notification can take to a recipient.
 *
 * Only channels the platform can actually deliver on exist here. Push arrived with
 * the Integration capability it needed (ADR 0045); WhatsApp arrives when its own
 * does (ADR 0017).
 */
enum NotificationChannel: string
{
    use HasDisplayLabel;

    case DATABASE = 'database';
    case MAIL = 'mail';
    case SMS = 'sms';

    /**
     * A registered device (ADR 0045). What travels is a type and a record id — the
     * device fetches the message over the authenticated API, because a push payload
     * passes through Google and Apple and a lock screen is a public surface.
     */
    case PUSH = 'push';

    /**
     * Whether a user may switch this channel off.
     *
     * The in-app record is always written: it is the audit trail of what the platform
     * decided to tell someone, and silencing it would leave no evidence a
     * notification was ever raised.
     */
    public function isOptional(): bool
    {
        return $this !== self::DATABASE;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
