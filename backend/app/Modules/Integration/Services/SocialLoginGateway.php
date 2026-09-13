<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\SocialLoginGatewayContract;
use App\Modules\Integration\Contracts\SocialLoginProviderContract;
use App\Modules\Integration\Data\SocialAuthorizationRequest;
use App\Modules\Integration\Data\SocialCodeExchange;
use App\Modules\Integration\Data\SocialProfile;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Exceptions\CredentialDecryptionException;
use App\Modules\Integration\Exceptions\SocialProviderException;
use App\Modules\Integration\Exceptions\SocialProviderUnavailableException;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use Throwable;

/**
 * Social login through the configured providers, recording every exchange (ADR 0017,
 * ADR 0050).
 *
 * ## No failover
 *
 * A person chose the provider, and an authorization code belongs to the vendor that
 * issued it. Handing a Google code to anyone else produces a rejection, not a second
 * opinion, so the selected provider's answer is final — the same reasoning captcha gives.
 *
 * ## Effective, not merely active
 *
 * ADR 0038 separates a provider being stored from being switched on from being usable.
 * This capability declares its required configuration as a shipped driver, a client id
 * setting and a client secret credential; a row missing any of them is never offered
 * and never used, whatever order the fields were saved in.
 *
 * ## What the usage log holds
 *
 * The provider, the outcome, a platform-written error code and message, and how long it
 * took. Never the authorization code, the verifier, a token or a credential.
 */
class SocialLoginGateway implements SocialLoginGatewayContract
{
    public function __construct(private readonly SocialLoginManager $manager) {}

    public function availableProviders(): array
    {
        return IntegrationProvider::query()
            ->forCapability(IntegrationCapability::SOCIAL_LOGIN)
            ->active()
            ->orderBy('priority')
            ->orderBy('driver')
            ->get()
            ->filter(fn (IntegrationProvider $provider): bool => $this->isEffective($provider))
            ->map(static fn (IntegrationProvider $provider): array => [
                'key' => $provider->driver,
                'label' => $provider->label,
            ])
            ->values()
            ->all();
    }

    public function isAvailable(string $provider): bool
    {
        return $this->effectiveProvider($provider) !== null;
    }

    public function providerStatuses(): array
    {
        return IntegrationProvider::query()
            ->forCapability(IntegrationCapability::SOCIAL_LOGIN)
            ->orderBy('priority')
            ->orderBy('driver')
            ->get()
            ->map(function (IntegrationProvider $provider): array {
                $missing = $this->missingConfiguration($provider);

                return [
                    'key' => $provider->driver,
                    'label' => $provider->label,
                    'active' => $provider->is_active,
                    'effective' => $provider->is_active && $this->isEffective($provider),
                    'client_id_configured' => ! in_array('client_id', $missing, true),
                    'client_secret_configured' => ! in_array('client_secret', $missing, true),
                    'missing' => $missing,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The required configuration a provider row lacks, by field name, never by value.
     *
     * Reads the row as it is in memory, so the admin update can ask before it saves. A
     * secret that cannot be decrypted counts as missing: it cannot be used.
     *
     * @return list<string>
     */
    public function missingConfiguration(IntegrationProvider $provider): array
    {
        $missing = [];
        $clientId = $provider->settings['client_id'] ?? null;

        if (! is_string($clientId) || trim($clientId) === '') {
            $missing[] = 'client_id';
        }

        if (! $this->hasClientSecret($provider)) {
            $missing[] = 'client_secret';
        }

        return $missing;
    }

    public function authorizationUrl(string $provider, SocialAuthorizationRequest $request): string
    {
        $row = $this->effectiveProvider($provider) ?? throw new SocialProviderUnavailableException($provider);

        return $this->driverFor($row)->authorizationUrl($request, $row);
    }

    public function exchange(string $provider, SocialCodeExchange $exchange): SocialProfile
    {
        $row = $this->effectiveProvider($provider) ?? throw new SocialProviderUnavailableException($provider);

        $startedAt = hrtime(true);

        try {
            $profile = $this->driverFor($row)->exchange($exchange, $row);
        } catch (SocialProviderException $e) {
            $this->record($row, $startedAt, $e);

            throw $e;
        } catch (CredentialDecryptionException) {
            $failure = new SocialProviderException('CREDENTIALS_UNREADABLE', 'The provider credentials could not be read.');
            $this->record($row, $startedAt, $failure);

            throw $failure;
        } catch (Throwable $e) {
            // The class and nothing else. An unexpected exception's message can carry
            // whatever the code that raised it had in hand, including a request body.
            $failure = new SocialProviderException('DRIVER_ERROR', 'The provider driver failed unexpectedly ('.class_basename($e).').');
            $this->record($row, $startedAt, $failure);

            throw $failure;
        }

        $this->record($row, $startedAt);

        return $profile;
    }

    private function effectiveProvider(string $provider): ?IntegrationProvider
    {
        if (! in_array($provider, SocialLoginManager::DRIVERS, true)) {
            return null;
        }

        $row = IntegrationProvider::query()
            ->forCapability(IntegrationCapability::SOCIAL_LOGIN)
            ->active()
            ->where('driver', $provider)
            ->first();

        return $row !== null && $this->isEffective($row) ? $row : null;
    }

    private function isEffective(IntegrationProvider $provider): bool
    {
        return in_array($provider->driver, SocialLoginManager::DRIVERS, true)
            && $this->missingConfiguration($provider) === [];
    }

    private function hasClientSecret(IntegrationProvider $provider): bool
    {
        if (! $provider->hasCredentials()) {
            return false;
        }

        try {
            $secret = $provider->getCredentials()['client_secret'] ?? null;
        } catch (CredentialDecryptionException) {
            return false;
        }

        return is_string($secret) && $secret !== '';
    }

    private function driverFor(IntegrationProvider $provider): SocialLoginProviderContract
    {
        /** @var SocialLoginProviderContract */
        return $this->manager->driver($provider->driver);
    }

    private function record(IntegrationProvider $provider, int $startedAt, ?SocialProviderException $failure = null): void
    {
        IntegrationUsageLog::query()->create([
            'integration_provider_id' => $provider->id,
            'capability' => $provider->capability,
            'driver' => $provider->driver,
            'status' => $failure === null ? UsageStatus::SUCCESS : UsageStatus::FAILURE,
            'reference' => null,
            'error_code' => $failure?->errorCode,
            'error_message' => $failure?->getMessage(),
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }
}
