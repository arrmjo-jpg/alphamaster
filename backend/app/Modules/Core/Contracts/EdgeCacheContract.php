<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Delivery\EdgeInvalidationReceipt;

/**
 * The CDN edge cache, as every module sees it (ADR 0036, ADR 0053).
 *
 * Declared in Core so any module — the ones that ship and the domain modules that come
 * later — can invalidate the edge without depending on Integration, and without knowing
 * which vendor fronts the platform. Integration binds it to the configured CDN driver;
 * with no CDN configured, Core's null implementation answers `not_configured`.
 *
 * This is the third cache layer and only that one. It does not touch the application
 * cache (`PlatformCacheContract`) and it does not decide what the origin marks cacheable
 * (the HTTP cache profiles). An invalidation here removes objects from edge nodes.
 */
interface EdgeCacheContract
{
    /**
     * Queue an invalidation. Returns once it is recorded, never after the vendor answered.
     *
     * Call it after the change it reflects has committed: an edge purged before the
     * transaction lands refetches the old representation and keeps it.
     *
     * @param  string|null  $reason  why, in an operator's words or a module's event name
     */
    public function invalidate(EdgeInvalidation $invalidation, ?string $reason = null): EdgeInvalidationReceipt;

    /**
     * The response header the configured edge reads cache tags from, or null when there
     * is no edge or it does not support tags.
     */
    public function tagHeader(): ?string;
}
