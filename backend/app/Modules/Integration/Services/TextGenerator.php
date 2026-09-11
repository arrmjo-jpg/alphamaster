<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Ai\TextGenerationResult;
use App\Modules\Core\Ai\TextGeneratorContract;
use App\Modules\Integration\Contracts\AiProviderContract;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Exceptions\CredentialDecryptionException;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use InvalidArgumentException;

/**
 * Generation through the configured provider, recorded, and with no failover.
 *
 * The absence of a chain is the decision this class exists to hold (ADR 0044 §3).
 * `SmsDispatcher` walks every active provider until one succeeds, because a recipient
 * cannot tell which carrier delivered a message. A generator is not interchangeable:
 * falling to a second vendor produces a *different answer*, which makes the output
 * irreproducible and a quality problem impossible to attribute. An operator who has
 * configured two vendors has expressed a preference, not a redundancy.
 *
 * Several providers may be configured at once, each with its own key and model; the one
 * marked default answers the platform's AI tasks.
 *
 * **The model belongs to the provider.** A task may name one; otherwise the model saved
 * with the answering provider applies, and failing that the driver's own default — so a
 * provider is never sent another vendor's model.
 *
 * Every attempt is recorded, successful or not, with the units the vendor reported.
 * What is never recorded is the prompt or the answer: a usage log exists to operate
 * the integration, and one carrying prompts would be a copy of the platform's content
 * in a table with different access rules (ADR 0017, ADR 0044 §8).
 */
class TextGenerator implements TextGeneratorContract
{
    public function __construct(private readonly AiManager $manager) {}

    public function generate(TextGenerationRequest $request): TextGenerationResult
    {
        $provider = $this->manager->defaultProvider();

        if ($provider === null) {
            // Not an exception. A caller that asked for a suggestion needs to show
            // somebody why there is none, and "no provider is configured" is an answer
            // rather than a fault — the platform is allowed to have no AI.
            return TextGenerationResult::failure(
                'none',
                'NOT_CONFIGURED',
                'No active AI provider is configured.'
            );
        }

        return $this->generateWith($provider, $request);
    }

    /**
     * Generate through one named provider — the default for a task, or a provider being
     * tested before it is saved, which may carry a key and model held only in memory.
     */
    public function generateWith(IntegrationProvider $provider, TextGenerationRequest $request): TextGenerationResult
    {
        $startedAt = hrtime(true);

        try {
            $result = $this->driverFor($provider)->generate(
                $request->withModel($this->modelFor($provider, $request->model)),
                $provider
            );
        } catch (CredentialDecryptionException $e) {
            // Unreadable credentials are a configuration fault for this provider. With
            // no chain to fall down, it is simply the outcome.
            $result = TextGenerationResult::failure($provider->driver, 'CREDENTIALS_UNREADABLE', $e->getMessage());
        } catch (\Throwable $e) {
            $result = TextGenerationResult::failure($provider->driver, 'DRIVER_ERROR', $e->getMessage());
        }

        $this->record($provider, $result, (int) ((hrtime(true) - $startedAt) / 1_000_000));

        return $result;
    }

    /**
     * The model a provider answers with: the one a task asked for, else the one saved
     * with the provider, else the driver's own default.
     */
    public function modelFor(IntegrationProvider $provider, ?string $requested = null): string
    {
        foreach ([$requested, $provider->settings['model'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return $this->driverFor($provider)->defaultModel();
    }

    public function driverFor(IntegrationProvider $provider): AiProviderContract
    {
        $driver = $this->manager->driver($provider->driver);

        if (! $driver instanceof AiProviderContract) {
            throw new InvalidArgumentException("[{$provider->driver}] is not an AI driver.");
        }

        return $driver;
    }

    /**
     * Whether a request would reach a vendor.
     *
     * Configuration only. A provider that is active and holds credentials is one a
     * request can be sent to; whether the vendor is up is knowable only by asking, and
     * a method that pretended otherwise would have every consumer trusting a guess.
     */
    public function isConfigured(): bool
    {
        $provider = $this->manager->defaultProvider();

        return $provider !== null && $provider->hasCredentials();
    }

    private function record(IntegrationProvider $provider, TextGenerationResult $result, int $durationMs): void
    {
        IntegrationUsageLog::query()->create([
            'integration_provider_id' => $provider->id,
            'capability' => $provider->capability,
            'driver' => $provider->driver,
            'status' => $result->successful ? UsageStatus::SUCCESS : UsageStatus::FAILURE,
            'reference' => null,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'duration_ms' => $durationMs,
            'units' => $result->units,
        ]);
    }
}
