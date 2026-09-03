<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\SmsDispatcherContract;
use App\Modules\Integration\Data\SmsMessage;
use App\Modules\Integration\Data\SmsResult;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Exceptions\CredentialDecryptionException;
use App\Modules\Integration\Exceptions\NoProviderConfiguredException;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;

/**
 * Sends through the configured chain, falling back on failure and recording every
 * attempt (ADR 0017).
 */
class SmsDispatcher implements SmsDispatcherContract
{
    public function __construct(private readonly SmsManager $manager) {}

    /**
     * Try each active provider in order until one succeeds.
     *
     * Every attempt is logged, successful or not, so a silent failover still leaves
     * evidence that the primary vendor is failing.
     *
     * @throws NoProviderConfiguredException
     */
    public function send(SmsMessage $message): SmsResult
    {
        $chain = $this->manager->providerChain();

        if ($chain->isEmpty()) {
            throw new NoProviderConfiguredException($this->manager->capability());
        }

        $last = null;

        foreach ($chain as $provider) {
            $result = $this->attempt($message, $provider);
            $last = $result;

            if ($result->successful) {
                return $result;
            }
        }

        // Every provider failed; the caller gets the last failure rather than an
        // exception, because an undeliverable message is an outcome, not a fault.
        return $last;
    }

    /**
     * One attempt against one provider, timed and recorded.
     */
    private function attempt(SmsMessage $message, IntegrationProvider $provider): SmsResult
    {
        $startedAt = hrtime(true);

        try {
            $result = $this->manager->driver($provider->driver)->send($message, $provider);
        } catch (CredentialDecryptionException $e) {
            // Unreadable credentials are a configuration fault for this provider, not
            // for the request: record it and let the chain continue to the next one.
            $result = SmsResult::failure($provider->driver, 'CREDENTIALS_UNREADABLE', $e->getMessage());
        } catch (\Throwable $e) {
            $result = SmsResult::failure($provider->driver, 'DRIVER_ERROR', $e->getMessage());
        }

        $this->record($provider, $result, (int) ((hrtime(true) - $startedAt) / 1_000_000));

        return $result;
    }

    /**
     * Persist the attempt. The message body and recipient are deliberately absent:
     * a usage log is for operating the integration, not for storing its content.
     */
    private function record(IntegrationProvider $provider, SmsResult $result, int $durationMs): void
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
