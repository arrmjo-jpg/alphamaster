<?php

declare(strict_types=1);

namespace App\Modules\Notification\Enums;

/**
 * A route a notification can take to a recipient.
 *
 * Only channels the platform can actually deliver on exist here. WhatsApp and push
 * arrive when the Integration capabilities they need do (ADR 0017).
 */
enum NotificationChannel: string
{
    case DATABASE = 'database';
    case MAIL = 'mail';
    case SMS = 'sms';

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
