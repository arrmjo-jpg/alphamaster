<?php

declare(strict_types=1);

namespace App\Modules\Localization\Controllers\Admin;

use App\Modules\Core\Contracts\EffectiveGrants;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\TranslationBatch;
use App\Modules\Localization\Requests\AcceptReadyTranslationsRequest;
use App\Modules\Localization\Requests\AcceptTranslationRequest;
use App\Modules\Localization\Requests\TranslateContentRequest;
use App\Modules\Localization\Services\BatchAcceptance;
use App\Modules\Localization\Services\SuggestionCoordinator;
use App\Modules\Localization\Services\TranslationBatchRefusedException;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI translation of items: asking for it, and deciding about it (ADR 0044, ADR 0056).
 *
 * The item is the unit throughout. An operator asks for one item, for a source, or for
 * everything missing in a language, and never for a field; they review an item and accept it
 * once; and "accept all ready" is that same acceptance, item by item.
 *
 * Three permissions are in play and they are deliberately not one:
 *
 *   * **`ai.use`** to ask. Every request costs money on the operator's account.
 *   * **the source's own write permission** to ask *and* to decide — translating content
 *     somebody may not write would spend money on something they cannot use, and would hand
 *     them the source text of content they were not granted.
 *   * **nothing extra** to read. What was generated is shown in the workshop, only to whoever
 *     may write the content it is for.
 *
 * The target language is always the one the request names. The console's own language — the
 * `X-Locale` a request carries — decides which language the answer's messages are in, and has
 * nothing to do with which language is being written.
 */
class TranslationBatchController extends BaseApiController
{
    public function __construct(
        private readonly SuggestionCoordinator $coordinator,
        private readonly BatchAcceptance $acceptance,
        private readonly EffectiveGrants $grants,
    ) {}

    /**
     * Translate with AI: one item, one source, or everything missing in a language.
     *
     * Answers immediately with how many items were started. Nothing is generated yet — a
     * generation takes seconds and belongs on a queue (ADR 0044 §4) — so the reply is an
     * acknowledgement and the workshop follows the items' status. An item already being
     * translated or waiting for review is counted as `existing`, and nothing new is started
     * for it.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{queued: int, existing: int, skipped: int}}')]
    #[Response(422, description: 'The language does not exist, or no AI provider is configured. Nothing is translated into the language a source is written in.')]
    public function store(TranslateContentRequest $request): JsonResponse
    {
        if (! $this->coordinator->available()) {
            // Said plainly rather than queued and failed one job at a time.
            return $this->errorResponse('AI_NOT_CONFIGURED', 'api.error.translations.ai_not_configured', null, 422);
        }

        $locale = (string) $request->validated('locale');

        /** @var Language|null $language */
        $language = Language::query()->where('code', $locale)->first();

        // Any language the platform knows, served or not (ADR 0048 §2).
        if ($language === null) {
            return $this->errorResponse('UNKNOWN_LOCALE', 'api.error.translations.unknown_locale', null, 422, ['locale' => $locale]);
        }

        $source = $request->validated('source');
        $item = $request->validated('item');

        $outcome = $this->coordinator->request(
            locale: $locale,
            permissions: $this->grants->permissionsFor($request->user()),
            sourceKey: is_string($source) && $source !== '' ? $source : null,
            itemId: is_string($item) && $item !== '' ? $item : null,
            includeTranslated: (bool) $request->validated('include_translated', false),
            requestedBy: $this->actor($request),
        );

        return $this->successResponse($outcome, 'api.translations.batches_queued', replace: [
            'count' => (string) $outcome['queued'],
        ]);
    }

    /**
     * Accept one item's translation, once, with whatever the reviewer changed.
     *
     * `values` carries only the fields the reviewer edited; every other field is written as it
     * was generated. All of them are written together.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{id: string, status: string, edited: bool}}')]
    #[Response(403, description: 'The caller may not change this kind of content.')]
    #[Response(409, description: 'The translation is not ready, or the item changed after it was generated.')]
    #[Response(422, description: 'A field is unknown, too long, or required and empty, or the owning module refused the result.')]
    public function accept(AcceptTranslationRequest $request, TranslationBatch $batch): JsonResponse
    {
        /** @var array<string, string|null> $values */
        $values = $request->validated('values', []);

        try {
            $batch = $this->acceptance->accept(
                $batch,
                $values,
                $this->grants->permissionsFor($request->user()),
                $this->actor($request),
            );
        } catch (TranslationBatchRefusedException $e) {
            return $this->refusal($e);
        }

        return $this->successResponse(
            ['id' => (string) $batch->getKey(), 'status' => $batch->status->value, 'edited' => $batch->edited],
            'api.translations.batch_accepted'
        );
    }

    /**
     * Accept every item ready for review in a language, item by item.
     *
     * One refused item is reported with its reason and does not stop the others, so the answer
     * is 200 with the outcome of each rather than an error for the first.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{accepted: int, failed: int, results: array<int, array{batch: string, source: string, item_id: string, status: string, error_code: string|null, message: string|null}>}}')]
    #[Response(422, description: 'The language does not exist.')]
    public function acceptReady(AcceptReadyTranslationsRequest $request): JsonResponse
    {
        $locale = (string) $request->validated('locale');

        if (! Language::query()->where('code', $locale)->exists()) {
            return $this->errorResponse('UNKNOWN_LOCALE', 'api.error.translations.unknown_locale', null, 422, ['locale' => $locale]);
        }

        $source = $request->validated('source');

        $outcome = $this->acceptance->acceptReady(
            $locale,
            is_string($source) && $source !== '' ? $source : null,
            $this->grants->permissionsFor($request->user()),
            $this->actor($request),
        );

        return $this->successResponse($outcome, 'api.translations.batches_accepted', replace: [
            'accepted' => (string) $outcome['accepted'],
            'failed' => (string) $outcome['failed'],
        ]);
    }

    /**
     * Discard an item's translation without writing anything.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{id: string, status: string}}')]
    #[Response(403, description: 'The caller may not change this kind of content.')]
    public function destroy(Request $request, TranslationBatch $batch): JsonResponse
    {
        try {
            $batch = $this->acceptance->dismiss($batch, $this->grants->permissionsFor($request->user()));
        } catch (TranslationBatchRefusedException $e) {
            return $this->refusal($e);
        }

        return $this->successResponse(
            ['id' => (string) $batch->getKey(), 'status' => $batch->status->value],
            'api.translations.batch_dismissed'
        );
    }

    private function refusal(TranslationBatchRefusedException $e): JsonResponse
    {
        return $this->errorResponse($e->errorCode, $e->messageKey, null, $e->status, $e->parameters);
    }

    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
