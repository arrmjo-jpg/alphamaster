<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Ai\TextGenerationResult;
use App\Modules\Integration\Models\IntegrationProvider;

/**
 * One AI vendor.
 *
 * The same shape `SmsProviderContract` has, and for the same reasons: configuration
 * comes from the database rather than from config files, and a refusal is returned
 * rather than thrown (ADR 0017).
 *
 * Written against Laravel's HTTP client rather than a vendor SDK, which adds no
 * dependency, keeps the driver coupled to our contract instead of a vendor's client,
 * and leaves it fully exercisable through `Http::fake()` — which is how the whole of
 * this capability is proven without anybody's API key.
 */
interface AiProviderContract
{
    /**
     * The driver name this implementation answers to.
     */
    public function driver(): string;

    /**
     * The model used when an administrator has not chosen one for this provider.
     *
     * One of this vendor's own models, so a provider can never be asked for another
     * vendor's model by default.
     */
    public function defaultModel(): string;

    /**
     * A short list of this vendor's models to offer in the Admin.
     *
     * Suggestions, not a catalogue: vendors rename and retire models on their own
     * schedule, so the Admin also accepts any model identifier typed by hand.
     *
     * @return list<string>
     */
    public function suggestedModels(): array;

    /**
     * Ask the vendor for text. The request's model is always set by the time it
     * arrives here.
     */
    public function generate(TextGenerationRequest $request, IntegrationProvider $provider): TextGenerationResult;
}
