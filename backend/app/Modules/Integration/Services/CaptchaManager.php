<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\CaptchaProviderContract;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Services\Captcha\RecaptchaProvider;

/**
 * Resolves captcha drivers by name, following Laravel's Manager convention.
 *
 * Identical in shape to SmsManager, and inheriting the same provider selection from
 * ProviderManager: the default driver comes from the database rather than a config
 * file, so a vendor is swapped from the Admin UI without a deploy.
 */
class CaptchaManager extends ProviderManager
{
    public function capability(): IntegrationCapability
    {
        return IntegrationCapability::CAPTCHA;
    }

    protected function createRecaptchaDriver(): CaptchaProviderContract
    {
        return $this->container->make(RecaptchaProvider::class);
    }
}
