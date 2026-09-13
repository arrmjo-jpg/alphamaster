<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\Modules\Integration\Data\SocialAuthorizationRequest;
use App\Modules\Integration\Data\SocialCodeExchange;
use App\Modules\Integration\Data\SocialProfile;
use App\Modules\Integration\Exceptions\SocialProviderException;
use App\Modules\Integration\Exceptions\SocialProviderUnavailableException;

/**
 * What a consumer depends on to sign somebody in through a social provider.
 *
 * The counterpart of CaptchaVerifierContract. Selecting the provider, deciding whether
 * it is usable and recording the attempt are part of this, not the caller's job. The
 * contract lives in Integration and the consumer in Auth, and the architecture suite
 * keeps the dependency pointing that way.
 *
 * "Available" means effective in ADR 0038's sense: the row is active and holds every
 * piece of configuration the capability requires. A provider that is merely switched on
 * is not offered.
 */
interface SocialLoginGatewayContract
{
    /**
     * The providers a client may offer, as key and label, in display order.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function availableProviders(): array;

    public function isAvailable(string $provider): bool;

    /**
     * Every configured provider and how far it is from usable, for an operator: whether
     * it is switched on, whether it is effective, and which required fields it lacks, by
     * name and never by value.
     *
     * @return list<array{key: string, label: string, active: bool, effective: bool, client_id_configured: bool, client_secret_configured: bool, missing: list<string>}>
     */
    public function providerStatuses(): array;

    /**
     * @throws SocialProviderUnavailableException
     */
    public function authorizationUrl(string $provider, SocialAuthorizationRequest $request): string;

    /**
     * @throws SocialProviderUnavailableException
     * @throws SocialProviderException
     */
    public function exchange(string $provider, SocialCodeExchange $exchange): SocialProfile;
}
