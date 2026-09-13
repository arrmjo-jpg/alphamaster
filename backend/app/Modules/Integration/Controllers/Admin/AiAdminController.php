<?php

declare(strict_types=1);

namespace App\Modules\Integration\Controllers\Admin;

use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Ai\TextGeneratorContract;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Integration\Services\AiStatus;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * The AI control centre.
 *
 * Two operations, and the split between them is the point. Reading the state is a
 * read: which vendor is selected, whether it holds a credential, what the last attempt
 * did. Asking the vendor a question is a *call* — it costs money and it leaves a usage
 * record — so it needs the permission that governs spending rather than the one that
 * governs looking.
 *
 * Nothing here reveals a credential. The API has never read one back (ADR 0017) and
 * a status endpoint is exactly where that would be tempting: an operator debugging a
 * failure wants to see the key, and the honest answer is that they can replace it and
 * try again.
 */
class AiAdminController extends BaseApiController
{
    public function __construct(
        private readonly AiStatus $status,
        private readonly TextGeneratorContract $generator,
    ) {}

    /**
     * What the platform can currently do with AI.
     *
     * Answers three separate questions rather than one summary: whether a vendor is
     * selected, whether it holds a credential, and whether it last answered. An
     * interface that ran them together would send somebody to fix the wrong thing.
     */
    #[Response(200, type: 'array{success: bool, data: array{configured: bool, provider: array{driver: string, label: string, has_credentials: bool}|null, available_drivers: array<int, string>, last_attempt: array{status: string, at: string, error_code: string|null, error_message: string|null, duration_ms: int|null, units: int|null}|null, recent_failures: int}}')]
    public function show(): JsonResponse
    {
        return $this->successResponse($this->status->describe());
    }

    /**
     * Ask the configured vendor to answer, and report what happened.
     *
     * A real call with a real cost — a handful of tokens — because a health check that
     * did not call the vendor would only re-report the configuration the operator is
     * already looking at. The prompt is fixed here and trivially small: it exists to
     * exercise credentials, network and model name, not to be useful.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{answered: bool, driver: string, units: int|null, error_code: string|null, error_message: string|null}}')]
    public function check(): JsonResponse
    {
        $result = $this->generator->generate(new TextGenerationRequest(
            instruction: 'Reply with the single word OK. Do not explain.',
            content: 'ping',
            model: $this->model(),
            maxOutputTokens: 16,
            temperature: 0.0,
        ));

        return $this->successResponse(
            [
                'answered' => $result->successful,
                'driver' => $result->driver,
                'units' => $result->units,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ],
            // 200 either way: the check ran, and what it found is the payload. A 502
            // for a vendor that refused would make a working diagnostic look broken.
            $result->successful ? 'api.ai.check_answered' : 'api.ai.check_failed'
        );
    }

    /**
     * The model a check uses: the one translations use, because that is the one whose
     * name being wrong is worth discovering.
     */
    private function model(): string
    {
        $configured = setting('ai.translation_model', 'gpt-4o-mini');

        return is_string($configured) && $configured !== '' ? $configured : 'gpt-4o-mini';
    }
}
