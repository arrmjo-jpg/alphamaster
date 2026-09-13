<?php

declare(strict_types=1);

namespace App\Modules\Localization\Controllers\Admin;

use App\Modules\Core\Contracts\EffectiveGrants;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Translation\TranslationRefusedException;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Core\Translation\UnknownTranslationTargetException;
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\TranslationSuggestion;
use App\Modules\Localization\Requests\AcceptSuggestionRequest;
use App\Modules\Localization\Requests\RequestSuggestionsRequest;
use App\Modules\Localization\Services\SuggestionCoordinator;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proposed translations: asking for them, reading them, and deciding.
 *
 * Separate from the workshop's own controller because the lifecycle is separate. A
 * translation is written the moment somebody presses save; a suggestion is asked for,
 * arrives later, and then has to be read by a person before anything is written at all
 * (ADR 0044 §5).
 *
 * Three permissions are in play and they are deliberately not one:
 *
 *   * **`ai.use`** to ask. Every request costs money on the operator's account.
 *   * **the source's own write permission** to ask *and* to accept — proposing a
 *     translation for content somebody may not write would spend money on something
 *     they cannot use, and would hand them the source text of content they were not
 *     granted.
 *   * **nothing extra** to read. A suggestion is only readable by whoever could see
 *     the content it proposes for, which the source's permissions already decide.
 *
 * Accepting writes through `TranslationSource::write`, the same path the workshop's own
 * save takes — so an accepted suggestion is versioned, audited and rolled back exactly
 * like a translation somebody typed. Nothing here reaches a table directly.
 */
class TranslationSuggestionController extends BaseApiController
{
    public function __construct(
        private readonly SuggestionCoordinator $coordinator,
        private readonly TranslationRegistry $registry,
        private readonly EffectiveGrants $grants,
    ) {}

    /**
     * Ask for translations of what is missing in a language.
     *
     * Answers immediately with what was queued. Nothing is generated yet — a
     * generation takes seconds and belongs on a queue (ADR 0044 §4) — so the reply is
     * an acknowledgement rather than a result, and the client polls.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{queued: int, skipped: int}}')]
    #[Response(422, description: 'The language is not served, or no AI provider is configured.')]
    public function store(RequestSuggestionsRequest $request): JsonResponse
    {
        if (! $this->coordinator->available()) {
            // Said plainly rather than queued and failed one job at a time. An operator
            // who has not configured a vendor should be told that, not billed with
            // thirty failures that each say the same thing.
            return $this->errorResponse(
                'AI_NOT_CONFIGURED',
                'api.error.translations.ai_not_configured',
                null,
                422
            );
        }

        $locale = (string) $request->validated('locale');

        if (! $this->isActiveLocale($locale)) {
            return $this->errorResponse(
                'UNKNOWN_LOCALE',
                'api.error.translations.unknown_locale',
                null,
                422,
                ['locale' => $locale]
            );
        }

        $outcome = $this->coordinator->request(
            locale: $locale,
            permissions: $this->grants->permissionsFor($request->user()),
            sourceKey: $request->validated('source'),
            itemId: $request->validated('item'),
            includeTranslated: (bool) $request->validated('include_translated', false),
            requestedBy: $request->user()?->getAuthIdentifier(),
        );

        return $this->successResponse($outcome, 'api.translations.suggestions_queued', replace: [
            'count' => (string) $outcome['queued'],
        ]);
    }

    /**
     * Every suggestion the caller may see for one language.
     *
     * Includes the failed ones. A translator who asked for thirty and got twenty-eight
     * needs to know which two did not arrive and why, and a list that quietly omitted
     * them would look like the request had been smaller than it was.
     */
    #[Response(200, type: 'array{success: bool, data: array<int, array{id: string, source: string, item_id: string, field: string, locale: string, status: string, status_label: string, source_text: string, existing_text: string|null, suggestion: string|null, error_code: string|null, error_message: string|null, completed_at: string|null}>}')]
    public function index(Request $request): JsonResponse
    {
        $locale = (string) $request->query('locale', '');
        $permissions = $this->grants->permissionsFor($request->user());

        $rows = TranslationSuggestion::query()
            ->when($locale !== '', fn ($query) => $query->where('locale', $locale))
            ->whereIn('status', [
                SuggestionStatus::PENDING->value,
                SuggestionStatus::READY->value,
                SuggestionStatus::FAILED->value,
            ])
            ->orderBy('source_key')
            ->orderBy('item_id')
            ->orderBy('field')
            ->get()
            // A suggestion carries the source text of the content it proposes for, so
            // it is readable by exactly whoever may write that content and nobody else.
            ->filter(fn (TranslationSuggestion $row): bool => $this->mayWrite($row->source_key, $permissions))
            ->values();

        return $this->successResponse(
            $rows->map(fn (TranslationSuggestion $row): array => $this->present($row))->all()
        );
    }

    /**
     * Write a suggestion through, as the person accepting it wants it.
     *
     * The text comes from the request rather than from the row, because the whole point
     * is that a person may have edited it before deciding. What is stored is which of
     * those happened: a suggestion accepted unchanged and one a translator rewrote are
     * different facts about the same row.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{id: string, status: string, edited: bool}}')]
    #[Response(409, description: 'The translation changed after this suggestion was made.')]
    public function accept(AcceptSuggestionRequest $request, TranslationSuggestion $suggestion): JsonResponse
    {
        $permissions = $this->grants->permissionsFor($request->user());

        if (! $this->mayWrite($suggestion->source_key, $permissions)) {
            return $this->errorResponse('FORBIDDEN', 'api.error.translations.write_refused', null, 403, [
                'source' => $suggestion->source_key,
            ]);
        }

        $source = $this->registry->find($suggestion->source_key);

        if ($source === null) {
            return $this->errorResponse('UNKNOWN_TRANSLATION_SOURCE', 'api.error.translations.unknown_source', null, 404, [
                'source' => $suggestion->source_key,
            ]);
        }

        // The guard that makes this safe. A suggestion is generated against what the
        // field held at the time; if somebody wrote a translation while it was queued,
        // applying it would overwrite work nobody was shown (ADR 0044 §5).
        if ($suggestion->targetMoved($this->currentValue($suggestion))) {
            return $this->errorResponse(
                'TRANSLATION_MOVED',
                'api.error.translations.suggestion_stale',
                null,
                409
            );
        }

        $text = (string) $request->validated('text');

        try {
            $source->write($suggestion->item_id, $suggestion->locale, [$suggestion->field => $text]);
        } catch (UnknownTranslationTargetException $e) {
            return $this->errorResponse('UNKNOWN_TRANSLATION_TARGET', $e->translationKey(), null, 404, $e->translationParameters());
        } catch (TranslationRefusedException $e) {
            return $this->errorResponse('TRANSLATION_REFUSED', $e->translationKey(), null, 422, $e->translationParameters());
        }

        $suggestion->forceFill([
            'status' => SuggestionStatus::ACCEPTED,
            'edited' => trim($text) !== trim((string) $suggestion->suggestion),
            'resolved_at' => now(),
        ])->save();

        return $this->successResponse(
            ['id' => $suggestion->id, 'status' => $suggestion->status->value, 'edited' => $suggestion->edited],
            'api.translations.suggestion_accepted'
        );
    }

    /**
     * Discard a suggestion without writing anything.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{id: string, status: string}}')]
    public function destroy(Request $request, TranslationSuggestion $suggestion): JsonResponse
    {
        if (! $this->mayWrite($suggestion->source_key, $this->grants->permissionsFor($request->user()))) {
            return $this->errorResponse('FORBIDDEN', 'api.error.translations.write_refused', null, 403, [
                'source' => $suggestion->source_key,
            ]);
        }

        $suggestion->forceFill([
            'status' => SuggestionStatus::DISMISSED,
            'resolved_at' => now(),
        ])->save();

        return $this->successResponse(
            ['id' => $suggestion->id, 'status' => $suggestion->status->value],
            'api.translations.suggestion_dismissed'
        );
    }

    /**
     * What the field holds now, read through the source rather than remembered.
     */
    private function currentValue(TranslationSuggestion $suggestion): ?string
    {
        $source = $this->registry->find($suggestion->source_key);

        if ($source === null) {
            return null;
        }

        foreach ($source->entries() as $entry) {
            if ($entry->id !== $suggestion->item_id) {
                continue;
            }

            foreach ($entry->fields as $field) {
                if ($field->name === $suggestion->field) {
                    return $field->valueFor($suggestion->locale);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function mayWrite(string $sourceKey, array $permissions): bool
    {
        $source = $this->registry->find($sourceKey);

        return $source !== null && in_array($source->writePermission(), $permissions, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TranslationSuggestion $row): array
    {
        return [
            'id' => $row->id,
            'source' => $row->source_key,
            'item_id' => $row->item_id,
            'field' => $row->field,
            'locale' => $row->locale,
            'status' => $row->status->value,
            'status_label' => $row->status->label(),
            'source_text' => $row->source_text,
            'existing_text' => $row->existing_text,
            'suggestion' => $row->suggestion,
            'error_code' => $row->error_code,
            'error_message' => $row->error_message,
            'completed_at' => $row->completed_at?->toIso8601String(),
        ];
    }

    private function isActiveLocale(string $locale): bool
    {
        return Language::query()->where('is_active', true)->where('code', $locale)->exists();
    }
}
