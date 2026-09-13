<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Ai;

use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Ai\TextGenerationResult;
use App\Modules\Integration\Contracts\AiProviderContract;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Services\ProviderHttp;

/**
 * OpenAI's chat completions API, over the HTTP client rather than the vendor SDK.
 *
 * The endpoint, the authentication and the wire format belong to the driver, not to
 * configuration: an administrator sets up OpenAI with a key and a model, and never
 * with an address or a header (ADR 0044).
 */
class OpenAiProvider implements AiProviderContract
{
    private const BASE_URL = 'https://api.openai.com/v1';

    /**
     * OpenAI's cost-optimised current chat model, which is what short translations
     * need. Checked against the vendor's model list on 2026-09-11.
     *
     * Chat models only. A reasoning model rejects `max_tokens` and `temperature`, which
     * this driver sends, so none is suggested — one typed by hand is refused by the
     * vendor, and the connection test says so before anything is saved.
     */
    private const DEFAULT_MODEL = 'gpt-5.6-luna';

    private const SUGGESTED_MODELS = ['gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.6-sol'];

    public function driver(): string
    {
        return 'openai';
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
                'The OpenAI provider needs an API key.'
            );
        }

        try {
            $response = ProviderHttp::client(AiTimeout::seconds())
                ->withToken($apiKey)
                ->post(self::BASE_URL.'/chat/completions', [
                    'model' => $request->model,
                    'max_tokens' => $request->maxOutputTokens,
                    'temperature' => $request->temperature,
                    'messages' => [
                        ['role' => 'system', 'content' => $request->systemPrompt()],
                        ['role' => 'user', 'content' => $request->content],
                    ],
                ]);
        } catch (\Throwable $e) {
            // A timeout or a DNS failure. Normal enough to report as an outcome: the
            // operator sees "the vendor did not answer", which is what happened.
            return TextGenerationResult::failure($this->driver(), 'TRANSPORT_ERROR', $e->getMessage());
        }

        if (! $response->successful()) {
            return TextGenerationResult::failure(
                $this->driver(),
                (string) ($response->json('error.code') ?? $response->status()),
                (string) ($response->json('error.message') ?? 'The OpenAI request failed.')
            );
        }

        $text = $response->json('choices.0.message.content');

        if (! is_string($text) || trim($text) === '') {
            // A 200 carrying nothing usable. Reported rather than passed on, because a
            // blank suggestion offered to a translator looks like the platform's fault.
            return TextGenerationResult::failure(
                $this->driver(),
                'EMPTY_RESPONSE',
                'The vendor answered without any text.'
            );
        }

        $units = $response->json('usage.total_tokens');

        return TextGenerationResult::success(
            $this->driver(),
            trim($text),
            is_int($units) ? $units : null
        );
    }
}
