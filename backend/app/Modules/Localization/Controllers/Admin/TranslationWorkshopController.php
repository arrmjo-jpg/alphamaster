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
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Requests\WriteTranslationRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 */
class TranslationWorkshopController extends BaseApiController
{
    public function __construct(
        private readonly TranslationRegistry $registry,
        private readonly EffectiveGrants $grants,
    ) {}

    /**
     * Every translatable body of content, and how far each language has got.
     *
     * The locales are the platform's active languages, so the columns an operator is
     * asked to fill are the ones the platform actually serves.
     */
    #[Response(200, type: 'array{success: bool, data: array{locales: array<int, array{code: string, name: string, native_name: string, direction: string, is_default: bool}>, sources: array<int, array{key: string, label: string, may_write: bool, entries: array<int, array{id: string, title: string, context: string|null, fields: array<int, array{name: string, label: string, multiline: bool, values: array<string, string>}>}>, completeness: array<string, array{total: int, translated: int}>}>}}')]
    public function index(Request $request): JsonResponse
    {
        $permissions = $this->grants->permissionsFor($request->user());
        $locales = $this->activeLocales();

        $sources = [];

        foreach ($this->registry->all() as $source) {
            if (! $this->mayView($source, $permissions)) {
                // Absent rather than empty. An operator who cannot read notification
                // wording is not helped by a heading over nothing, and a count of
                // untranslated items they cannot see is a statement about content
                // they were not granted.
                continue;
            }

            $entries = $source->entries();

            $sources[] = [
                'key' => $source->key(),
                'label' => __($source->label()),
                'may_write' => in_array($source->writePermission(), $permissions, true),
                'entries' => array_map(fn (TranslationEntry $entry): array => [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'context' => $entry->context,
                    'fields' => array_map(fn ($field): array => [
                        'name' => $field->name,
                        'label' => __($field->label),
                        'multiline' => $field->multiline,
                        // Only what has been written. A fallback here would show the
                        // English in the Arabic column and make an untranslated item
                        // look finished, which is the exact confusion this exists to
                        // remove.
                        'values' => $field->values,
                    ], $entry->fields),
                ], $entries),
                'completeness' => $this->completeness($entries, $locales),
            ];
        }

        return $this->successResponse([
            'locales' => array_map(fn (Language $language): array => [
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
                'direction' => $language->direction->value,
                'is_default' => $language->is_default,
            ], $locales),
            'sources' => $sources,
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
    #[Response(422, description: 'The language is not served, or the write would leave the content in a state its owner does not allow.')]
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
        if (! $this->mayView($found, $permissions)) {
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

        if (! $this->isActiveLocale($locale)) {
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

        return $this->successResponse(
            ['source' => $source, 'id' => $id, 'locale' => $locale],
            'api.translations.written'
        );
    }

    /**
     * How many of a source's fields are written in each locale.
     *
     * Counted in fields rather than items, because an item is finished only when
     * every one of its fields is — a template with an Arabic subject and an English
     * body is not half translated in any sense a reader would recognise.
     *
     * @param  array<int, TranslationEntry>  $entries
     * @param  array<int, Language>  $locales
     * @return array<string, array{total: int, translated: int}>
     */
    private function completeness(array $entries, array $locales): array
    {
        $counts = [];

        foreach ($locales as $language) {
            $total = 0;
            $translated = 0;

            foreach ($entries as $entry) {
                foreach ($entry->fields as $field) {
                    $total++;

                    if ($field->hasValueFor($language->code)) {
                        $translated++;
                    }
                }
            }

            $counts[$language->code] = ['total' => $total, 'translated' => $translated];
        }

        return $counts;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function mayView(TranslationSource $source, array $permissions): bool
    {
        $required = $source->viewPermission();

        return $required === null || in_array($required, $permissions, true);
    }

    /**
     * @return array<int, Language>
     */
    private function activeLocales(): array
    {
        /** @var array<int, Language> $languages */
        $languages = Language::query()->where('is_active', true)->ordered()->get()->all();

        return $languages;
    }

    private function isActiveLocale(string $locale): bool
    {
        foreach ($this->activeLocales() as $language) {
            if ($language->code === $locale) {
                return true;
            }
        }

        return false;
    }
}
