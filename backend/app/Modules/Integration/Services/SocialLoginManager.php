<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\SocialLoginProviderContract;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Services\SocialLogin\GoogleSocialLoginProvider;

/**
 * Resolves social login drivers by name, following Laravel's Manager convention.
 *
 * The same shape as CaptchaManager. Unlike the other managers its default driver means
 * nothing: providers are chosen by the person signing in, not by the platform, so the
 * gateway always names the driver it wants.
 */
class SocialLoginManager extends ProviderManager
{
    /**
     * Every driver this platform ships. A provider row naming anything else is never
     * offered, whatever its configuration says.
     *
     * @var array<int, string>
     */
    public const DRIVERS = ['google'];

    public function capability(): IntegrationCapability
    {
        return IntegrationCapability::SOCIAL_LOGIN;
    }

    protected function createGoogleDriver(): SocialLoginProviderContract
    {
        return $this->container->make(GoogleSocialLoginProvider::class);
    }
}
