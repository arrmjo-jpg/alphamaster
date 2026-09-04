<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

enum MfaType: string
{
    use HasDisplayLabel;

    case TOTP = 'totp';
    case SMS_OTP = 'sms_otp';

    /**
     * Whether this method is strong enough to satisfy the mandatory requirement for
     * administrators (ADR 0013).
     *
     * SMS is not. SIM swap and SS7 interception are practical attacks, and NIST
     * SP 800-63B treats an out-of-band SMS authenticator as restricted. Having made
     * administrator MFA compulsory for security reasons, allowing exactly those
     * accounts to satisfy it with the weakest available factor would undo the point.
     */
    public function satisfiesAdministratorPolicy(): bool
    {
        return $this === self::TOTP;
    }

    /**
     * Whether the platform must deliver a code before this method can be answered.
     */
    public function requiresDelivery(): bool
    {
        return $this === self::SMS_OTP;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
