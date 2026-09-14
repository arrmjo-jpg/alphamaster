<?php

declare(strict_types=1);

namespace App\Modules\Localization\Controllers\Admin;

use App\Modules\Core\Contracts\EffectiveGrants;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationField;
use App\Modules\Core\Translation\TranslationItemStatus;
use App\Modules\Core\Translation\TranslationRefusedException;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Core\Translation\TranslationSource;
use App\Modules\Core\Translation\UnknownTranslationTargetException;
use App\Modules\Localization\Enums\BatchStatus;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\TranslationBatch;
use App\Modules\Localization\Models\TranslationSuggestion;
use App\Modules\Localization\Requests\WorkshopQueryRequest;
use App\Modules\Localization\Requests\WriteTranslationRequest;
use App\Modules\Localization\Services\SuggestionCoordinator;
use App\Modules\Localization\Services\TranslationAudit;
use App\Modules\Localization\Services\TranslationCoverage;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Everything the platform has to say, in every language it says it in.
 *
 * The languages screen manages *which* languages exist. This manages what is written in them.
 * The content itself belongs to other modules and stays there: this assembles what they declare
 * through `TranslationSource` and writes back through the same seam, which is why Localization
 * can serve a workshop over content it may not import (ADR 0043).
 *
 * Every language the platform knows is here the moment it is added, with every existing item
 * in it — nothing is registered per language and no empty row is created, because an item with
 * no text in a language is simply not translated there (ADR 0056).
 *
 * The item is the unit (ADR 0056). Each has one status in the target language — not translated,
 * incomplete, being translated, ready for review, translated, failed — worked out from its
 * fields' metadata and its open translation batch, and never from what its fields are called.
 * The workshop knows nothing about a page body or a role label; it knows which fields are
 * required, of which type, in which group.
 *
 * Authorization is per source, not per screen (ADR 0043 §3). An operator sees the sources they
 * may read, writes only the ones they may change, and sees what AI generated only for content
 * they may write.
 *
 * It is queried rather than downloaded (ADR 0048 §4): one target language, filtered and
 * searched here, one page at a time.
 */
class TranslationWorkshopController extends BaseApiController
{
    public function __construct(
        private readonly TranslationRegistry $registry,
        private readonly EffectiveGrants $grants,
        private readonly TranslationCoverage $coverage,
        private readonly SuggestionCoordinator $coordinator,
        private readonly TranslationAudit $audit,
    ) {}

    /**
     * One language's items, filtered, searched and a page at a time.
     *
     * `locales` lists every language the platform knows, served or not, so a draft can be
     * chosen as the target. The source is always the default language. `completeness` counts
     * items translated out of items; `statuses` counts the items of a source in each status.
     */
    #[Response(200, type: 'array{success: bool, data: array{locales: array<int, array{code: string, name: string, native_name: string, direction: string, is_default: bool, is_active: bool}>, source_locale: string|null, target: string|null, coverage: array{total: int, translated: int}, sources: array<int, array{key: string, label: string, may_write: bool, source_locale: string|null, completeness: array{total: int, translated: int}, statuses: array{not_translated: int, incomplete: int, pending: int, ready: int, translated: int, failed: int}}>, entries: array<int, array{source: string, id: string, title: string, context: string|null, status: "not_translated"|"incomplete"|"pending"|"ready"|"translated"|"failed", status_label: string, progress: array{filled: int, total: int, complete: bool}, fields: array<int, array{name: string, label: string, multiline: bool, required: bool, type: "plain_text"|"html", group: "content"|"seo", max_length: int|null, translatable: bool, values: array<string, string>}>, batch: null|array{id: string, status: "pending"|"ready"|"failed"|"accepted"|"dismissed", status_label: string, fields_total: int, fields_ready: int, fields_failed: int, error_code: string|null, error_message: string|null, completed_at: string|null, suggestions: array<int, array{field: string, status: string, text: string|null, error_code: string|null, error_message: string|null}>}}>, pagination: array{page: int, per_page: int, total: int, last_page: int}}}')]
    #[Response(422, description: 'The target is not a language the platform knows.')]
    public function index(WorkshopQueryRequest $request): JsonResponse
    {
        $permissions = $this->grants->permissionsFor($request->user());

        /** @var array<int, Language> $languages */
        $languages = Language::query()->ordered()->get()->all();
        $codes = array_map(static fn (Language $language): string => $language->code, $languages);

        $sourceLocale = $this->defaultCode($languages);

        $requested = trim((string) $request->validated('target', ''));

        if ($requested !== '' && ! in_array($requested, $codes, true)) {
            return $this->errorResponse(
                'UNKNOWN_LOCALE',
                'api.error.translations.unknown_locale',
                null,
                422,
                ['locale' => $requested]
            );
        }

        $target = $requested !== '' ? $requested : $this->firstTarget($codes, $sourceLocale);

        $state = (string) ($request->validated('state') ?? 'all');
        $search = mb_strtolower(trim((string) $request->validated('search', '')));
        $only = trim((string) $request->validated('source', ''));
        $perPage = (int) $request->validated('per_page', WorkshopQueryRequest::DEFAULT_PER_PAGE);
        $page = (int) $request->validated('page', 1);

        $open = $target === null ? [] : $this->coordinator->openBatches($target);

        $sources = [];
        $coverage = ['total' => 0, 'translated' => 0];
        $matches = [];

        foreach ($this->coverage->viewableSources($permissions) as $source) {
            $entries = $source->entries();
            $mayWrite = in_array($source->writePermission(), $permissions, true);
            $statuses = array_fill_keys(TranslationItemStatus::values(), 0);

            $counts = $target === null
                ? ['total' => 0, 'translated' => 0]
                : $this->coverage->count($entries, [$target])[$target];

            $coverage['total'] += $counts['total'];
            $coverage['translated'] += $counts['translated'];

            // The language this source is translated from: its own, or the default (ADR 0049).
            $from = $this->coordinator->sourceLocaleOf($source, $sourceLocale);

            foreach ($target === null ? [] : $entries as $entry) {
                if ($entry->translatableFields() === []) {
                    continue;
                }

                // What AI generated is shown only to whoever may write the content — it carries
                // the text, and a batch for content the caller cannot accept is not theirs.
                $batch = $mayWrite ? ($open[$this->coordinator->addressOf($source->key(), $entry->id)] ?? null) : null;
                $status = $this->statusOf($entry, $target, $batch);
                $statuses[$status->value]++;

                if (($only === '' || $source->key() === $only)
                    && ($state === 'all' || $status->value === $state)
                    && $this->matchesSearch($entry, $search, $from, $target)) {
                    $matches[] = [$source, $entry, $status, $batch, $from];
                }
            }

            $sources[] = [
                'key' => $source->key(),
                'label' => __($source->label()),
                'may_write' => $mayWrite,
                'source_locale' => $from,
                'completeness' => $counts,
                'statuses' => $statuses,
            ];
        }

        $total = count($matches);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        $entries = array_map(
            fn (array $match): array => $this->present($match[0], $match[1], $match[2], $match[3], $match[4], (string) $target),
            array_slice($matches, ($page - 1) * $perPage, $perPage)
        );

        return $this->successResponse([
            'locales' => array_map(fn (Language $language): array => [
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
                'direction' => $language->direction->value,
                'is_default' => $language->is_default,
                'is_active' => $language->is_active,
            ], $languages),
            'source_locale' => $sourceLocale,
            'target' => $target,
            'coverage' => $coverage,
            'sources' => $sources,
            'entries' => $entries,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    /**
     * Write one item's fields in one language, typed by a person.
     *
     * One item and one locale per request, deliberately. A translator works down a list and a
     * failure should cost the entry they are on rather than a batch they did not know they were
     * sending.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{source: string, id: string, locale: string}}')]
    #[Response(403, description: 'The caller may not change this kind of content.')]
    #[Response(404, description: 'No such source, or no such item within it.')]
    #[Response(422, description: 'The language does not exist, or the write would leave the content in a state its owner does not allow.')]
    public function update(WriteTranslationRequest $request, string $source, string $id): JsonResponse
    {
        $found = $this->registry->find($source);

        if ($found === null) {
            return $this->errorResponse(
                'UNKNOWN_TRANSLATION_SOURCE',
                'api.error.translations.unknown_source',
                null,
                404,
                ['source' => $source]
            );
        }

        $permissions = $this->grants->permissionsFor($request->user());

        // Both, and in this order. Refusing a write on content the caller cannot even read must
        // not distinguish "you may not write this" from "there is no such item", because the
        // second answer describes content they were not granted.
        if (! $this->coverage->mayView($found, $permissions)) {
            return $this->errorResponse(
                'UNKNOWN_TRANSLATION_SOURCE',
                'api.error.translations.unknown_source',
                null,
                404,
                ['source' => $source]
            );
        }

        if (! in_array($found->writePermission(), $permissions, true)) {
            return $this->errorResponse(
                'FORBIDDEN',
                'api.error.translations.write_refused',
                null,
                403,
                ['source' => $source]
            );
        }

        $locale = (string) $request->validated('locale');

        // Any language the platform knows, served or not (ADR 0048 §2): a draft is translated
        // before it is published, not after.
        if (! Language::query()->where('code', $locale)->exists()) {
            return $this->errorResponse(
                'UNKNOWN_LOCALE',
                'api.error.translations.unknown_locale',
                null,
                422,
                ['locale' => $locale]
            );
        }

        /** @var array<string, string|null> $values */
        $values = $request->validated('values');

        $before = $this->audit->snapshot($found, $id, $locale);

        try {
            $found->write($id, $locale, $values);
        } catch (UnknownTranslationTargetException $e) {
            return $this->errorResponse(
                'UNKNOWN_TRANSLATION_TARGET',
                $e->translationKey(),
                null,
                404,
                $e->translationParameters()
            );
        } catch (TranslationRefusedException $e) {
            // The source's own reason, reported rather than interpreted. Only the module that
            // owns the content knows what a valid translation of it is.
            return $this->errorResponse(
                'TRANSLATION_REFUSED',
                $e->translationKey(),
                null,
                422,
                $e->translationParameters()
            );
        }

        $this->audit->record(
            $found->key(),
            $id,
            $locale,
            $this->audit->changedFields($before, $values),
            TranslationAudit::ORIGIN_MANUAL
        );

        return $this->successResponse(
            ['source' => $source, 'id' => $id, 'locale' => $locale],
            'api.translations.written'
        );
    }

    /**
     * An item's status: a translation in progress where there is one, otherwise what is written.
     */
    private function statusOf(TranslationEntry $entry, string $target, ?TranslationBatch $batch): TranslationItemStatus
    {
        return match ($batch?->status) {
            BatchStatus::PENDING => TranslationItemStatus::PENDING,
            BatchStatus::READY => TranslationItemStatus::READY,
            BatchStatus::FAILED => TranslationItemStatus::FAILED,
            default => $entry->statusIn($target),
        };
    }

    /**
     * Whether the search appears in what an operator would read: the item's title and context,
     * and its text in the source and target languages.
     */
    private function matchesSearch(TranslationEntry $entry, string $needle, ?string $sourceLocale, string $target): bool
    {
        if ($needle === '') {
            return true;
        }

        $haystacks = [$entry->title, (string) $entry->context];

        foreach ($entry->fields as $field) {
            $haystacks[] = (string) ($sourceLocale === null ? '' : $field->valueFor($sourceLocale));
            $haystacks[] = (string) $field->valueFor($target);
        }

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One item as the workshop shows it: its status and progress, its fields as their source
     * describes them with only what has actually been written in the source and target
     * languages, and its open translation if there is one. A fallback here would put the default
     * language's text in the target column and make an untranslated item look finished
     * (ADR 0043 §4).
     *
     * @return array<string, mixed>
     */
    private function present(
        TranslationSource $source,
        TranslationEntry $entry,
        TranslationItemStatus $status,
        ?TranslationBatch $batch,
        ?string $sourceLocale,
        string $target,
    ): array {
        return [
            'source' => $source->key(),
            'id' => $entry->id,
            'title' => $entry->title,
            'context' => $entry->context,
            'status' => $status->value,
            'status_label' => $status->label(),
            'progress' => $entry->progressIn($target)->toArray(),
            'fields' => array_map(function (TranslationField $field) use ($sourceLocale, $target): array {
                $values = [];

                foreach (array_unique(array_filter([$sourceLocale, $target])) as $code) {
                    if ($field->hasValueFor($code)) {
                        $values[$code] = (string) $field->valueFor($code);
                    }
                }

                return $field->describe() + ['values' => $values];
            }, $entry->translatableFields()),
            'batch' => $batch === null ? null : $this->presentBatch($batch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBatch(TranslationBatch $batch): array
    {
        return [
            'id' => (string) $batch->getKey(),
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'fields_total' => $batch->fields_total,
            'fields_ready' => $batch->fields_ready,
            'fields_failed' => $batch->fields_failed,
            'error_code' => $batch->error_code,
            'error_message' => $batch->error_message,
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'suggestions' => $batch->suggestions->map(fn (TranslationSuggestion $row): array => [
                'field' => $row->field,
                'status' => $row->status->value,
                'text' => $row->suggestion,
                'error_code' => $row->error_code,
                'error_message' => $row->error_message,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, Language>  $languages
     */
    private function defaultCode(array $languages): ?string
    {
        foreach ($languages as $language) {
            if ($language->is_default) {
                return $language->code;
            }
        }

        return $languages[0]->code ?? null;
    }

    /**
     * The language a workshop opens on when nobody chose one: the first that is not the one
     * everything is translated from.
     *
     * @param  array<int, string>  $codes
     */
    private function firstTarget(array $codes, ?string $sourceLocale): ?string
    {
        foreach ($codes as $code) {
            if ($code !== $sourceLocale) {
                return $code;
            }
        }

        return null;
    }
}
