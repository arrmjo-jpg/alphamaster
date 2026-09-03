<?php

declare(strict_types=1);

namespace App\Modules\Notification\Enums;

/**
 * The registry of notifications the platform can raise.
 *
 * Preferences reference this rather than free text, so a stored preference always
 * names something real and a renamed notification cannot leave orphaned rows behind
 * that silently mean nothing.
 */
enum NotificationType: string
{
    case SECURITY_ALERT = 'security.alert';
    case ACCOUNT_UPDATED = 'account.updated';
    case ADMIN_ANNOUNCEMENT = 'admin.announcement';

    /**
     * Whether a recipient may opt out of this notification entirely.
     *
     * A security alert is not a preference. Letting someone silence the message that
     * tells them their account was compromised would be a setting that exists only to
     * be regretted.
     */
    public function isOptional(): bool
    {
        return $this !== self::SECURITY_ALERT;
    }

    /**
     * Channels this notification is delivered on unless a preference says otherwise.
     *
     * @return array<int, NotificationChannel>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::SECURITY_ALERT => [NotificationChannel::DATABASE, NotificationChannel::MAIL],
            self::ACCOUNT_UPDATED => [NotificationChannel::DATABASE, NotificationChannel::MAIL],
            self::ADMIN_ANNOUNCEMENT => [NotificationChannel::DATABASE],
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
