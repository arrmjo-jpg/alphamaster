<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

/**
 * The state of an authenticity assessment.
 *
 * No analyzer exists yet, so nothing sets anything but NOT_REQUESTED today. The enum
 * is defined so that when one arrives it expresses an assessment rather than a
 * verdict: an analyzer reports risk, and the platform must never record that a file
 * definitively is or is not machine generated.
 */
enum VerificationStatus: string
{
    case NOT_REQUESTED = 'not_requested';
    case PENDING = 'pending';
    case ASSESSED = 'assessed';
    case FAILED = 'failed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
