<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Ai;

use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Ai\TextGenerationResult;
use App\Modules\Integration\Contracts\AiProviderContract;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Services\ProviderHttp;

/**
 * Anthropic's messages API, over the HTTP client rather than the vendor SDK.
 *
 * A second driver exists from the start on purpose. One driver behind a manager is a
 * pattern nobody has tested; two prove that the seam is real — that a task asks for
 * text without knowing who answers, and that the request and result shapes are ours
 * rather than one vendor's borrowed.
 *
 * The differences are where a single-vendor design would have leaked: the system
 * prompt is a top-level field rather than a message, the version header is mandatory,
 * and usage is reported as separate input and output counts.
 *
 * The endpoint, the authentication and the wire format belong to the driver, not to
 * configuration: an administrator sets up Anthropic with a key and a model, and never
 * with an address or a header (ADR 0044).
 */
class AnthropicProvider implements AiProviderContract
{
    private const BASE_URL = 'https://api.anthropic.com/v1';

    /**
     * Pinned. The vendor requires it and treats it as the contract version; reading it
     * from configuration would let an operator select a wire format the driver was not
     * written against.
     */
    private const API_VERSION = '2023-06-01';

    /**
     * The balance of speed and quality the vendor recommends, and a model with a long
     * support window. The faster Haiku 4.5 is offered but not the default: its
     * retirement is committed no sooner than October 2026, which is too close for a
     * default nobody chose. Checked against the vendor's model list on 2026-09-11.
     */
    private const DEFAULT_MODEL = 'claude-sonnet-5';

    private const SUGGESTED_MODELS = ['claude-sonnet-5', 'claude-haiku-4-5', 'claude-opus-5'];

    public function driver(): string
    {
        return 'anthropic';
    }

    public function defaultModel(): string
    {
        return self::DEFAULT_MODEL;
    }

    public function suggestedModels(): array
    {
        return self::SUGGESTED_MODELS;
    }

    public function generate(TextGenerationRequest $request, IntegrationProvider $provider): TextGenerationResult
    {
        $apiKey = (string) ($provider->getCredentials()['api_key'] ?? '');

        if ($apiKey === '') {
            return TextGenerationResult::failure(
                $this->driver(),
                'MISCONFIGURED',
                'The Anthropic provider needs an API key.'
            );
        }

        try {
            $response = ProviderHttp::client(AiTimeout::seconds())
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => self::API_VERSION,
                ])
                ->post(self::BASE_URL.'/messages', [
                    'model' => $request->model,
                    'max_tokens' => $request->maxOutputTokens,
                    'temperature' => $request->temperature,
                    'system' => $request->systemPrompt(),
                    'messages' => [
                        ['role' => 'user', 'content' => $request->content],
                    ],
                ]);
        } catch (\Throwable $e) {
            return TextGenerationResult::failure($this->driver(), 'TRANSPORT_ERROR', $e->getMessage());
        }

        if (! $response->successful()) {
            return TextGenerationResult::failure(
                $this->driver(),
                (string) ($response->json('error.type') ?? $response->status()),
                (string) ($response->json('error.message') ?? 'The Anthropic request failed.')
            );
        }

        $text = $response->json('content.0.text');

        if (! is_string($text) || trim($text) === '') {
            return TextGenerationResult::failure(
                $this->driver(),
                'EMPTY_RESPONSE',
                'The vendor answered without any text.'
            );
        }

        return TextGenerationResult::success($this->driver(), trim($text), $this->units($response->json('usage')));
    }

    /**
     * Input and output tokens, added.
     *
     * Reported separately by this vendor and as one total by the other. Summed here so
     * the usage log holds one comparable number rather than a column whose meaning
     * depends on which driver wrote the row.
     */
    private function units(mixed $usage): ?int
    {
        if (! is_array($usage)) {
            return null;
        }

        $input = $usage['input_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;

        if (! is_int($input) && ! is_int($output)) {
            return null;
        }

        return (is_int($input) ? $input : 0) + (is_int($output) ? $output : 0);
    }
}
