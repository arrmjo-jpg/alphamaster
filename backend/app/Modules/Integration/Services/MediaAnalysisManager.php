<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Enums\IntegrationCapability;

/**
 * Resolves media analysis drivers by name (ADR 0017, ADR 0054).
 *
 * No driver ships yet, and deliberately: no vendor has been chosen, and there is no
 * log-style analyzer, for the reason captcha has none — a detector that answers without
 * looking at the media is not a degraded detector but the absence of one. Until a driver is
 * added, the capability reports itself not configured and every request says so.
 *
 * Adding a vendor means a `create<Name>Driver` method returning a
 * `MediaAnalysisProviderContract`, and a row in integration_providers.
 */
class MediaAnalysisManager extends ProviderManager
{
    public function capability(): IntegrationCapability
    {
        return IntegrationCapability::MEDIA_ANALYSIS;
    }
}
