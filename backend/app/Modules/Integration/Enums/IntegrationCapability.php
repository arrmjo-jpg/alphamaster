<?php

declare(strict_types=1);

namespace App\Modules\Integration\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * A class of external service the platform can consume.
 *
 * Only capabilities with a real consumer exist here. SMS is present because the OTP
 * multi-factor method deferred by ADR 0013 needs it; CAPTCHA because the sign-in
 * endpoint verifies one. Email, WhatsApp and storage arrive when the phases that
 * consume them do, which is what the manager pattern makes cheap.
 *
 * Adding a case is not enough on its own: the capability column carries a database
 * constraint listing the permitted values, so a new capability arrives with a
 * migration that widens it.
 */
enum IntegrationCapability: string
{
    use HasDisplayLabel;

    case SMS = 'sms';

    case CAPTCHA = 'captcha';

    /**
     * Text generation (ADR 0044). Present because the translation workshop asks for
     * suggestions; it is the one AI task the platform has a consumer for.
     */
    case AI = 'ai';

    /**
     * Push delivery to a registered device (ADR 0045). Firebase Cloud Messaging is a
     * driver here and nothing more: this platform owns identity, its database and its
     * media, and adopts Firebase only as a transport.
     */
    case PUSH = 'push';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
