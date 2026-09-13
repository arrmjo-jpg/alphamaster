<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Ai;

use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Ai\TextGenerationResult;
use App\Modules\Integration\Contracts\AiProviderContract;
use App\Modules\Integration\Models\IntegrationProvider;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI's chat completions API, over the HTTP client rather than the vendor SDK.
 *
 * The base URL is a provider setting rather than a constant, because every
 * OpenAI-compatible gateway — Azure's deployment endpoints, a self-hosted proxy, a
 * corporate egress gateway — speaks this shape at a different address. A constant
 * would mean a second driver for what is the same protocol.
 */
class OpenAiProvider implements AiProviderContract
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function driver(): string
    {
        return 'openai';
    }

    public function generate(TextGenerationRequest $request, IntegrationProvider $provider): TextGenerationResult
    {
        $apiKey = (string) ($provider->getCredentials()['api_key'] ?? '');

        if ($apiKey === '') {
            return TextGenerationResult::failure(
                $this->driver(),
                'MISCONFIGURED',
                'The OpenAI provider needs an api_key credential.'
            );
        }

        $base = $this->baseUrl($provider);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(AiTimeout::seconds())
                ->post($base.'/chat/completions', [
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

    /**
     * The endpoint to call.
     *
     * A provider setting rather than a constant, because every OpenAI-compatible
     * gateway speaks this protocol at a different address. Blank means the vendor's
     * own: the row ships with an empty string so an operator can see the field exists,
     * and an empty string is the absence of a choice rather than a choice of nothing.
     */
    private function baseUrl(IntegrationProvider $provider): string
    {
        $configured = $provider->settings['base_url'] ?? null;

        return rtrim(
            is_string($configured) && trim($configured) !== '' ? trim($configured) : self::DEFAULT_BASE_URL,
            '/'
        );
    }
}
