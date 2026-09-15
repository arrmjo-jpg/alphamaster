<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Core\MediaAnalysis\AnalyzerDescriptor;
use App\Modules\Core\MediaAnalysis\AnalyzerOutcome;
use App\Modules\Core\MediaAnalysis\MediaAnalysisInput;
use App\Modules\Core\MediaAnalysis\MediaAnalyzerContract;
use App\Modules\Integration\Contracts\MediaAnalysisProviderContract;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Exceptions\CredentialDecryptionException;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use InvalidArgumentException;
use Throwable;

/**
 * The media analyzer, backed by the configured Integration provider (ADR 0054).
 *
 * The default active provider for `media_analysis` answers, provided its driver exists and
 * its required configuration is present. There is no failover: two analyzers produce two
 * different readings of the same file, and falling silently to a second one would change what
 * the platform recorded without saying so (the reason ADR 0044 gives for AI).
 *
 * Every call is recorded in the usage log with one unit, so an operator can see what the
 * capability is spending and failing on.
 */
class IntegrationMediaAnalyzer implements MediaAnalyzerContract
{
    public function __construct(private readonly MediaAnalysisManager $manager) {}

    public function isConfigured(): bool
    {
        return $this->usableProvider() !== null;
    }

    public function descriptor(): ?AnalyzerDescriptor
    {
        $provider = $this->usableProvider();

        return $provider === null ? null : $this->driverFor($provider)->descriptor($provider);
    }

    public function analyze(MediaAnalysisInput $input): AnalyzerOutcome
    {
        $provider = $this->usableProvider();

        if ($provider === null) {
            return AnalyzerOutcome::failure('NOT_CONFIGURED', 'No media analysis provider is active and configured.', retryable: false);
        }

        $startedAt = hrtime(true);

        try {
            $outcome = $this->driverFor($provider)->analyze($input, $provider);
        } catch (CredentialDecryptionException $e) {
            $outcome = AnalyzerOutcome::failure('CREDENTIALS_UNREADABLE', $e->getMessage(), retryable: false);
        } catch (Throwable $e) {
            $outcome = AnalyzerOutcome::failure('DRIVER_ERROR', $e->getMessage(), retryable: true);
        }

        IntegrationUsageLog::query()->create([
            'integration_provider_id' => $provider->id,
            'capability' => $provider->capability,
            'driver' => $provider->driver,
            'status' => $outcome->successful ? UsageStatus::SUCCESS : UsageStatus::FAILURE,
            'reference' => $outcome->reference,
            'error_code' => $outcome->errorCode,
            'error_message' => $outcome->errorMessage,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'units' => 1,
        ]);

        return $outcome;
    }

    /**
     * The provider analyses run through: the default, active, with a driver and every
     * required field.
     */
    public function usableProvider(): ?IntegrationProvider
    {
        $provider = $this->manager->defaultProvider();

        if ($provider === null || ! $this->hasDriver($provider)) {
            return null;
        }

        return $this->driverFor($provider)->missingConfiguration($provider) === [] ? $provider : null;
    }

    public function driverFor(IntegrationProvider $provider): MediaAnalysisProviderContract
    {
        $driver = $this->manager->driver($provider->driver);

        if (! $driver instanceof MediaAnalysisProviderContract) {
            throw new InvalidArgumentException("[{$provider->driver}] is not a media analysis driver.");
        }

        return $driver;
    }

    public function hasDriver(IntegrationProvider $provider): bool
    {
        try {
            $this->driverFor($provider);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function missingConfiguration(IntegrationProvider $provider): array
    {
        return $this->hasDriver($provider) ? $this->driverFor($provider)->missingConfiguration($provider) : ['driver'];
    }
}
