<?php

declare(strict_types=1);

namespace App\Modules\Media\Services\Scanning;

use App\Modules\Media\Contracts\MediaScannerContract;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Models\MediaFile;

/**
 * The scanner used when no antivirus is available.
 *
 * It reports NOT_SCANNED, never CLEAN. That distinction is the entire point of this
 * class: a driver that returned CLEAN would make every media row assert a guarantee
 * no scanner ever produced, and an operator reading the column would have no way to
 * tell a scanned file from an unscanned one. Reporting honestly means a real scanner
 * can be introduced later and the difference is visible in the data.
 */
class NullMediaScanner implements MediaScannerContract
{
    public function scan(MediaFile $media): ScanStatus
    {
        return ScanStatus::NOT_SCANNED;
    }

    public function name(): string
    {
        return 'null';
    }
}
