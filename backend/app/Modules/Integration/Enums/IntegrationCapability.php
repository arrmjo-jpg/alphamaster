<?php

declare(strict_types=1);

namespace App\Modules\Integration\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * A class of external service the platform can consume.
 *
 * Only capabilities with a real consumer exist here. SMS is present because the OTP
 * multi-factor method deferred by ADR 0013 needs it; email, WhatsApp and storage
 * arrive when the phases that consume them do, which is what the manager pattern
 * makes cheap.
 */
enum IntegrationCapability: string
{
    use HasDisplayLabel;

    case SMS = 'sms';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
