<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Ai;

use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Ai\TextGenerationResult;
use App\Modules\Integration\Contracts\AiProviderContract;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Services\ProviderHttp;

/**
 * Google Gemini's `generateContent` API, over the HTTP client rather than a vendor SDK.
 *
 * The third shape behind the same contract, and the differences are the usual places a
 * single-vendor design leaks: the model is part of the URL rather than the body, the
 * key travels in its own header, the system prompt is a separate `systemInstruction`,
 * and the answer can arrive split across several parts.
 *
 * The endpoint, the authentication and the wire format belong to the driver, not to
 * configuration: an administrator sets up Gemini with a key and a model, and never
 * with an address or a header (ADR 0044).
 */
class GeminiProvider implements AiProviderContract
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Google's fastest, cheapest current stable model, which is what short translations
     * need. Checked against the vendor's model list on 2026-09-11.
     */
    private const DEFAULT_MODEL = 'gemini-3.5-flash-lite';

    private const SUGGESTED_MODELS = ['gemini-3.5-flash-lite', 'gemini-3.8-flash', 'gemini-2.5-pro'];

    public function driver(): string
    {
        return 'gemini';
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
                'The Google Gemini provider needs an API key.'
            );
        }

        // Google's own examples name models both ways; the URL takes the bare name.
        $model = (string) preg_replace('#^models/#', '', trim((string) $request->model));

        try {
            $response = ProviderHttp::client(AiTimeout::seconds())
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(self::BASE_URL.'/models/'.rawurlencode($model).':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $request->systemPrompt()]]],
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $request->content]]],
                    ],
                    'generationConfig' => [
                        'temperature' => $request->temperature,
                        'maxOutputTokens' => $request->maxOutputTokens,
                    ],
                ]);
        } catch (\Throwable $e) {
            return TextGenerationResult::failure($this->driver(), 'TRANSPORT_ERROR', $e->getMessage());
        }

        if (! $response->successful()) {
            return TextGenerationResult::failure(
                $this->driver(),
                (string) ($response->json('error.status') ?? $response->status()),
                (string) ($response->json('error.message') ?? 'The Google Gemini request failed.')
            );
        }

        $text = $this->text($response->json('candidates.0.content.parts'));

        if ($text === '') {
            return TextGenerationResult::failure(
                $this->driver(),
                'EMPTY_RESPONSE',
                'The vendor answered without any text.'
            );
        }

        $units = $response->json('usageMetadata.totalTokenCount');

        return TextGenerationResult::success($this->driver(), $text, is_int($units) ? $units : null);
    }

    /**
     * The answer's text, joined across parts. A part the model marks as its own
     * reasoning is not part of the answer and is left out.
     */
    private function text(mixed $parts): string
    {
        if (! is_array($parts)) {
            return '';
        }

        $text = '';

        foreach ($parts as $part) {
            if (is_array($part) && ($part['thought'] ?? false) !== true && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return trim($text);
    }
}
