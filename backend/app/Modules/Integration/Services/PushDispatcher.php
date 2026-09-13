<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\PushDispatcherContract;
use App\Modules\Integration\Data\PushMessage;
use App\Modules\Integration\Data\PushResult;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Exceptions\CredentialDecryptionException;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;

/**
 * Sends through the configured chain, falling back on failure and recording every
 * attempt.
 *
 * Failover applies here, unlike AI and for the reason AI is the exception: push *is* a
 * transport. A recipient cannot tell which service delivered a notification, so falling
 * to a second provider delivers the same thing (ADR 0045 §2).
 *
 * One case does not fall down the chain. When a vendor says the registration token no
 * longer exists, the address is dead rather than the vendor being unavailable — trying
 * a second vendor with the same dead token is a second failure, and the useful outcome
 * is telling the caller to remove the row.
 */
class PushDispatcher implements PushDispatcherContract
{
    public function __construct(private readonly PushManager $manager) {}

    public function send(PushMessage $message): PushResult
    {
        $chain = $this->manager->providerChain();

        if ($chain->isEmpty()) {
            // An outcome rather than an exception: a platform is allowed to have no
            // push provider, and the channel skips the route rather than failing the
            // notification's other channels.
            return PushResult::failure('none', 'NOT_CONFIGURED', 'No active push provider is configured.');
        }

        $last = null;

        foreach ($chain as $provider) {
            $result = $this->attempt($message, $provider);
            $last = $result;

            if ($result->successful || $result->tokenInvalid) {
                return $result;
            }
        }

        return $last;
    }

    public function isConfigured(): bool
    {
        $provider = $this->manager->defaultProvider();

        return $provider !== null && $provider->hasCredentials();
    }

    private function attempt(PushMessage $message, IntegrationProvider $provider): PushResult
    {
        $startedAt = hrtime(true);

        try {
            $result = $this->manager->driver($provider->driver)->send($message, $provider);
        } catch (CredentialDecryptionException $e) {
            $result = PushResult::failure($provider->driver, 'CREDENTIALS_UNREADABLE', $e->getMessage());
        } catch (\Throwable $e) {
            $result = PushResult::failure($provider->driver, 'DRIVER_ERROR', $e->getMessage());
        }

        $this->record($provider, $result, (int) ((hrtime(true) - $startedAt) / 1_000_000));

        return $result;
    }

    /**
     * Persist the attempt. The device token is deliberately absent, exactly as a
     * recipient's phone number is for SMS: a usage log is for operating the
     * integration, not for storing who was contacted.
     */
    private function record(IntegrationProvider $provider, PushResult $result, int $durationMs): void
    {
        IntegrationUsageLog::query()->create([
            'integration_provider_id' => $provider->id,
            'capability' => $provider->capability,
            'driver' => $provider->driver,
            'status' => $result->successful ? UsageStatus::SUCCESS : UsageStatus::FAILURE,
            'reference' => $result->reference,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'duration_ms' => $durationMs,
        ]);
    }
}
