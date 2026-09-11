<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Contracts\AiProviderContract;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Services\Ai\AnthropicProvider;
use App\Modules\Integration\Services\Ai\GeminiProvider;
use App\Modules\Integration\Services\Ai\OpenAiProvider;

/**
 * Resolves AI drivers by name, following Laravel's Manager convention.
 *
 * Adding a vendor means adding a create<Name>Driver method and a row in
 * `integration_providers`; nothing that asks for a suggestion changes.
 *
 * There is no log-style driver here, and there will not be one. A generator that
 * answers without asking a vendor is not a degraded generator — it is a source of
 * plausible text with no relationship to the request, which is worse than no answer
 * at all when the answer is a translation somebody may accept. A platform with no AI
 * provider configured has no AI, and every consumer is built to say so.
 */
class AiManager extends ProviderManager
{
    public function capability(): IntegrationCapability
    {
        return IntegrationCapability::AI;
    }

    protected function createOpenaiDriver(): AiProviderContract
    {
        return $this->container->make(OpenAiProvider::class);
    }

    protected function createAnthropicDriver(): AiProviderContract
    {
        return $this->container->make(AnthropicProvider::class);
    }

    protected function createGeminiDriver(): AiProviderContract
    {
        return $this->container->make(GeminiProvider::class);
    }
}
