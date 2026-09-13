<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\PushProviderContract;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Services\Push\FcmProvider;

/**
 * Resolves push drivers by name, following Laravel's Manager convention.
 *
 * One driver today, and the pattern is what makes a second cheap: replacing Firebase
 * with APNs directly, or with any other push service, is a `create<Name>Driver` method
 * and a provider row (ADR 0045 §1). Nothing that sends a notification changes.
 *
 * There is no log-style driver. A push that is written to a file has not reached a
 * device, and a channel that reported success for one would make an operator believe
 * their notifications were arriving.
 */
class PushManager extends ProviderManager
{
    public function capability(): IntegrationCapability
    {
        return IntegrationCapability::PUSH;
    }

    protected function createFcmDriver(): PushProviderContract
    {
        return $this->container->make(FcmProvider::class);
    }
}
