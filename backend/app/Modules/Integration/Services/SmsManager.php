<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\SmsProviderContract;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Services\Sms\LogSmsProvider;
use App\Modules\Integration\Services\Sms\TwilioSmsProvider;

/**
 * Resolves SMS drivers by name, following Laravel's Manager convention.
 *
 * Adding a vendor means adding a create<Name>Driver method and a row in
 * integration_providers; nothing that sends a message changes.
 */
class SmsManager extends ProviderManager
{
    public function capability(): IntegrationCapability
    {
        return IntegrationCapability::SMS;
    }

    protected function createLogDriver(): SmsProviderContract
    {
        return $this->container->make(LogSmsProvider::class);
    }

    protected function createTwilioDriver(): SmsProviderContract
    {
        return $this->container->make(TwilioSmsProvider::class);
    }
}
