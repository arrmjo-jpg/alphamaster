<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * What a malware scanner concluded, if one ran.
 *
 * NOT_SCANNED exists so the platform never claims a guarantee nobody checked. No
 * antivirus is available in this environment, so the null driver records this rather
 * than CLEAN: a row asserting cleanliness on the strength of a scanner that did not
 * run would be worse than an honest absence.
 */
enum ScanStatus: string
{
    use HasDisplayLabel;

    case NOT_SCANNED = 'not_scanned';
    case PENDING = 'pending';
    case CLEAN = 'clean';
    case INFECTED = 'infected';
    case SCAN_ERROR = 'scan_error';

    /**
     * Whether this outcome positively permits serving the file.
     */
    public function permitsServing(): bool
    {
        return $this !== self::INFECTED;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
