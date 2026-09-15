<?php

declare(strict_types=1);

namespace App\Modules\Core\Delivery;

use App\Modules\Core\Contracts\EdgeCacheContract;

/**
 * The edge when there is none.
 *
 * Bound by Core only if nothing else is, so a platform assembled without Integration
 * still boots, and a module that invalidates the edge needs no conditional around it.
 */
final class NullEdgeCache implements EdgeCacheContract
{
    public function invalidate(EdgeInvalidation $invalidation, ?string $reason = null): EdgeInvalidationReceipt
    {
        return EdgeInvalidationReceipt::notConfigured();
    }

    public function tagHeader(): ?string
    {
        return null;
    }
}
