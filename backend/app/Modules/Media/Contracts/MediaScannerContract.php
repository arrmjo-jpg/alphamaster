<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Models\MediaFile;

/**
 * Malware scanning, kept provider independent.
 *
 * No antivirus exists in this environment, so the registered driver reports
 * NOT_SCANNED. It deliberately does not report CLEAN: a media row asserting
 * cleanliness on the strength of a scanner that never ran would be a guarantee nobody
 * checked, which is worse than an honest absence.
 */
interface MediaScannerContract
{
    /**
     * Scan the stored file and report what was concluded.
     */
    public function scan(MediaFile $media): ScanStatus;

    /**
     * The driver name, recorded so a later scan can be told from an earlier one.
     */
    public function name(): string;
}
