<?php

declare(strict_types=1);

namespace App\Modules\Notification\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * What kind of thing a registered device is.
 *
 * Three cases, and the list is closed because each one is a delivery route the
 * platform has to have a driver for. It is stored for the operator's benefit — a
 * device registry that says only "four devices" is less useful than one that says
 * which — and never branched on when sending: FCM addresses all three the same way.
 */
enum DevicePlatform: string
{
    use HasDisplayLabel;

    case IOS = 'ios';
    case ANDROID = 'android';

    /** A browser holding a service-worker subscription, which FCM also carries. */
    case WEB = 'web';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
