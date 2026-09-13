<?php

declare(strict_types=1);

namespace App\Modules\Localization\Controllers\Admin;

use App\Modules\Core\Contracts\EffectiveGrants;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationRefusedException;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Core\Translation\TranslationSource;
use App\Modules\Core\Translation\UnknownTranslationTargetException;
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Models\Language;
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
 * The languages screen manages *which* languages exist. This manages what is written
 * in them — and the two were never the same thing. Adding Arabic to the language list
 * did nothing to the roles, the settings copy or the notification wording, and there
 * was no surface anywhere that would tell an operator so.
 *
 * The content itself belongs to three other modules and stays there. This assembles
 * what they declare through `TranslationSource` and writes back through the same
 * seam, which is why Localization can serve a workshop over content it may not
 * import (ADR 0043).
 *
 * Authorization is per source, not per screen. Translating notification wording is
 * editing notification wording, so it needs `notifications.update` exactly as the
 * notifications screen does; a workshop that granted itself a way around that would be
 * an escalation with a friendly name. An operator sees the sources they may read and
 * writes only the ones they may change.
 *
 * It is queried rather than downloaded (ADR 0048 §4): one target language, filtered
 * and searched here, one page at a time. And that target may be a language the
 * platform does not serve yet — translating before publishing is the point of a draft.
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
     * One language's translations, filtered, searched and a page at a time.
     *
     * `locales` lists every language the platform knows, served or not, so a draft can
     * be chosen as the target. The source is always the default language.
     */
    #[Response(200, type: 'array{success: bool, data: array{locales: array<int, array{code: string, name: string, native_name: string, direction: string, is_default: bool, is_active: bool}>, source_locale: string|null, target: string|null, coverage: array{total: int, translated: int}, sources: array<int, array{key: string, label: string, may_write: bool, completeness: array{total: int, translated: int}}>, entries: array<int, array{source: string, id: string, title: string, context: string|null, fields: array<int, array{name: string, label: string, multiline: bool, values: array<string, string>}>}>, pagination: array{page: int, per_page: int, total: int, last_page: int}}}')]
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

        // The suggestion states the filter can ask about, keyed the way the workshop
        // addresses a field. Only this language's, and only what is stored.
        $reviewable = $target === null ? [] : $this->coordinator->forLocale($target);

        $sources = [];
        $coverage = ['total' => 0, 'translated' => 0];
        $matches = [];

        foreach ($this->coverage->viewableSources($permissions) as $source) {
            $entries = $source->entries();

            $counts = $target === null
                ? ['total' => 0, 'translated' => 0]
                : $this->coverage->count($entries, [$target])[$target];

            $coverage['total'] += $counts['total'];
            $coverage['translated'] += $counts['translated'];

            $sources[] = [
                'key' => $source->key(),
                'label' => __($source->label()),
                'may_write' => in_array($source->writePermission(), $permissions, true),
                'completeness' => $counts,
            ];

            if ($target === null || ($only !== '' && $source->key() !== $only)) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($this->matchesState($source, $entry, $target, $state, $reviewable)
                    && $this->matchesSearch($entry, $search, $sourceLocale, $target)) {
                    $matches[] = [$source, $entry];
                }
            }
        }

        $total = count($matches);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        $entries = array_map(
            fn (array $match): array => $this->present($match[0], $match[1], $sourceLocale, (string) $target),
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
     * Write one item's fields in one language.
     *
     * One item and one locale per request, deliberately. A translator works down a
     * list and a failure should cost the entry they are on rather than the batch they
     * did not know they were sending.
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

        // Both, and in this order. Refusing a write on content the caller cannot even
        // read must not distinguish "you may not write this" from "there is no such
        // item", because the second answer describes content they were not granted.
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

        // Any language the platform knows, served or not (ADR 0048 §2): a draft is
        // translated before it is published, not after.
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
            // The source's own reason, reported rather than interpreted. Only the
            // module that owns the content knows what a valid translation of it is.
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
     * Whether an entry is in the state the operator asked to see.
     *
     * @param  array<string, TranslationSuggestion>  $reviewable
     */
    private function matchesState(
        TranslationSource $source,
        TranslationEntry $entry,
        string $target,
        string $state,
        array $reviewable,
    ): bool {
        return match ($state) {
            'missing' => $entry->missingIn($target) > 0,
            'translated' => $entry->missingIn($target) === 0,
            'needs_review' => $this->hasSuggestion($source, $entry, SuggestionStatus::READY, $reviewable),
            'failed' => $this->hasSuggestion($source, $entry, SuggestionStatus::FAILED, $reviewable),
            default => true,
        };
    }

    /**
     * @param  array<string, TranslationSuggestion>  $reviewable
     */
    private function hasSuggestion(
        TranslationSource $source,
        TranslationEntry $entry,
        SuggestionStatus $status,
        array $reviewable,
    ): bool {
        foreach ($entry->fields as $field) {
            $row = $reviewable[$this->coordinator->addressOf($source->key(), $entry->id, $field->name)] ?? null;

            if ($row !== null && $row->status === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the search appears in what an operator would read: the item's title and
     * context, and its text in the source and target languages.
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
     * One entry as the workshop shows it: the source language beside the target, and
     * only what has actually been written in each. A fallback here would put the
     * English in the French column and make an untranslated item look finished
     * (ADR 0043 §4).
     *
     * @return array<string, mixed>
     */
    private function present(TranslationSource $source, TranslationEntry $entry, ?string $sourceLocale, string $target): array
    {
        return [
            'source' => $source->key(),
            'id' => $entry->id,
            'title' => $entry->title,
            'context' => $entry->context,
            'fields' => array_map(function ($field) use ($sourceLocale, $target): array {
                $values = [];

                foreach (array_unique(array_filter([$sourceLocale, $target])) as $code) {
                    if ($field->hasValueFor($code)) {
                        $values[$code] = (string) $field->valueFor($code);
                    }
                }

                return [
                    'name' => $field->name,
                    'label' => __($field->label),
                    'multiline' => $field->multiline,
                    'values' => $values,
                ];
            }, $entry->fields),
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
     * The language a workshop opens on when nobody chose one: the first that is not
     * the one everything is translated from.
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
