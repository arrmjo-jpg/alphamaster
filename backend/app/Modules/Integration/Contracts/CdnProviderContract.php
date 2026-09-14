<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Integration\Data\CdnLimits;
use App\Modules\Integration\Data\CdnPurgeResult;
use App\Modules\Integration\Data\CdnScopeReport;
use App\Modules\Integration\Models\IntegrationProvider;

/**
 * One CDN vendor, as the platform drives it (ADR 0017, ADR 0053 §4).
 *
 * Everything vendor-specific stops here: the API address, how a credential is presented,
 * how a scope is identified, what a prefix looks like on the wire, which header carries
 * cache tags, and the limits of each plan. Adding Bunny, Fastly or CloudFront is a class
 * implementing this and a `create<Name>Driver` method on the manager; nothing that
 * invalidates the edge changes.
 */
interface CdnProviderContract
{
    /**
     * The configuration fields this driver needs, by name. Settings are shown; credentials
     * are write-only.
     *
     * @return array{settings: list<string>, credentials: list<string>}
     */
    public function configurationFields(): array;

    /**
     * Required configuration the row lacks, by field name, never by value.
     *
     * @return list<string>
     */
    public function missingConfiguration(IntegrationProvider $provider): array;

    /**
     * The limits that apply to the row's scope, given what verification last detected.
     */
    public function limits(IntegrationProvider $provider): CdnLimits;

    /**
     * Ask the vendor about the configured scope, with the stored credential.
     */
    public function verify(IntegrationProvider $provider): CdnScopeReport;

    /**
     * One purge call. The invalidation already fits the limits: the caller split it.
     */
    public function purge(EdgeInvalidation $invalidation, IntegrationProvider $provider): CdnPurgeResult;

    /**
     * The response header this vendor reads cache tags from, or null if it has none.
     */
    public function tagHeader(): ?string;
}
