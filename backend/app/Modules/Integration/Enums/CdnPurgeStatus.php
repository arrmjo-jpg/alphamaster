<?php

declare(strict_types=1);

namespace App\Modules\Integration\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * Where one edge invalidation stands (ADR 0053 §4).
 *
 * `succeeded` means the vendor accepted the purge, never merely that it was queued.
 * `failed` is final and stays visible until an operator retries it: the objects it named
 * are still being served stale.
 */
enum CdnPurgeStatus: string
{
    use HasDisplayLabel;

    case PENDING = 'pending';

    case PROCESSING = 'processing';

    case SUCCEEDED = 'succeeded';

    case FAILED = 'failed';
}
