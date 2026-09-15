<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\CdnProviderContract;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Services\Cdn\CloudflareCdnProvider;

/**
 * Resolves CDN drivers by name (ADR 0017, ADR 0053).
 *
 * Adding a vendor means adding a create<Name>Driver method and a row in
 * integration_providers; nothing that invalidates the edge changes.
 */
class CdnManager extends ProviderManager
{
    public function capability(): IntegrationCapability
    {
        return IntegrationCapability::CDN;
    }

    protected function createCloudflareDriver(): CdnProviderContract
    {
        return $this->container->make(CloudflareCdnProvider::class);
    }
}
