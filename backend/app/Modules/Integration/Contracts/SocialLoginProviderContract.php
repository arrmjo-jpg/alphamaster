<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\Modules\Integration\Data\SocialAuthorizationRequest;
use App\Modules\Integration\Data\SocialCodeExchange;
use App\Modules\Integration\Data\SocialProfile;
use App\Modules\Integration\Exceptions\SocialProviderException;
use App\Modules\Integration\Models\IntegrationProvider;

/**
 * One social login vendor (ADR 0050 §10).
 *
 * Configured the way every vendor is: the provider row supplies the client id as a
 * setting and the client secret as an encrypted credential, so a vendor is wired up from
 * the Admin rather than from a config file (ADR 0017).
 */
interface SocialLoginProviderContract
{
    /**
     * The driver key this implementation answers to, and the value an identity's
     * `provider` column carries.
     */
    public function driver(): string;

    /**
     * Where to send a person to sign in.
     */
    public function authorizationUrl(SocialAuthorizationRequest $request, IntegrationProvider $provider): string;

    /**
     * Redeem an authorization code and return the validated identity.
     *
     * Every failure raises SocialProviderException with a platform-written message:
     * an identity is either established or it is not, and there is no partial answer a
     * caller could mistake for one.
     *
     * @throws SocialProviderException
     */
    public function exchange(SocialCodeExchange $exchange, IntegrationProvider $provider): SocialProfile;
}
