<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

/**
 * Where a file is in its intake lifecycle.
 *
 * Verification is deliberately absent: whether an analyzer has looked at a file is
 * independent of whether the file is usable, and coupling them would make readiness
 * hostage to a capability most media never needs.
 */
enum MediaStatus: string
{
    case UPLOADED = 'uploaded';
    case SCANNING = 'scanning';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case SCAN_FAILED = 'scan_failed';
    case PROCESSING_FAILED = 'processing_failed';

    /**
     * Whether the file may be served.
     */
    public function isServable(): bool
    {
        return $this === self::READY;
    }

    public function isFailure(): bool
    {
        return $this === self::SCAN_FAILED || $this === self::PROCESSING_FAILED;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
