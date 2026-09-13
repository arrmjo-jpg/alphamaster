<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

use App\Modules\Core\Ai\TextGeneratorContract;
use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationField;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Core\Translation\TranslationSource;
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Jobs\GenerateTranslationSuggestion;
use App\Modules\Localization\Models\TranslationSuggestion;
use Illuminate\Support\Facades\DB;

/**
 * Turns "translate what is missing" into rows and queued work.
 *
 * The decisions worth reading are about what it refuses to do.
 *
 * **It never requests a suggestion for a field that already has one outstanding.** A
 * translator who presses the button twice should not be billed twice, and a field with
 * two proposals gives a reviewer two answers with no way to tell which the accept
 * button applies.
 *
 * **It defaults to only what is untranslated.** Asking for a language means filling the
 * gaps; regenerating text somebody already wrote is a request an operator has to make
 * deliberately, per field, because the platform paying to replace human work by default
 * is the wrong default.
 *
 * **It records what the target held.** That snapshot is what makes accepting safe later:
 * a suggestion generated against an empty field cannot quietly overwrite a translation
 * written while it was queued.
 */
class SuggestionCoordinator
{
    public function __construct(
        private readonly TranslationRegistry $registry,
        private readonly TextGeneratorContract $generator,
    ) {}

    /**
     * Whether asking is possible at all.
     */
    public function available(): bool
    {
        return $this->generator->isConfigured();
    }

    /**
     * Queue suggestions for one locale.
     *
     * @param  array<int, string>  $permissions  what the requester holds
     * @param  string|null  $sourceKey  narrow to one body of content, or null for all
     * @param  string|null  $itemId  narrow to one item, or null for the whole source
     * @return array{queued: int, skipped: int}
     */
    public function request(
        string $locale,
        array $permissions,
        ?string $sourceKey = null,
        ?string $itemId = null,
        bool $includeTranslated = false,
        ?string $requestedBy = null,
    ): array {
        $queued = 0;
        $skipped = 0;

        foreach ($this->registry->all() as $key => $source) {
            if ($sourceKey !== null && $key !== $sourceKey) {
                continue;
            }

            // The owning module's write permission, asked exactly as the workshop asks
            // it. Proposing a translation for content the requester may not write would
            // spend money to produce something they cannot use — and would be a way to
            // read content they were not granted, since the source text comes back in
            // the suggestion.
            if (! in_array($source->writePermission(), $permissions, true)) {
                continue;
            }

            foreach ($source->entries() as $entry) {
                if ($itemId !== null && $entry->id !== $itemId) {
                    continue;
                }

                [$made, $passed] = $this->requestForEntry(
                    $source,
                    $entry,
                    $locale,
                    $includeTranslated,
                    $requestedBy
                );

                $queued += $made;
                $skipped += $passed;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Every outstanding or recently resolved suggestion for a locale, keyed by the
     * address the workshop uses.
     *
     * @return array<string, TranslationSuggestion>
     */
    public function forLocale(string $locale): array
    {
        $rows = TranslationSuggestion::query()
            ->where('locale', $locale)
            ->outstanding()
            ->orWhere(function ($query) use ($locale): void {
                $query->where('locale', $locale)
                    ->where('status', SuggestionStatus::FAILED->value);
            })
            ->get();

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$this->addressOf($row->source_key, $row->item_id, $row->field)] = $row;
        }

        return $keyed;
    }

    /**
     * The workshop's address for a field, as one string.
     */
    public function addressOf(string $sourceKey, string $itemId, string $field): string
    {
        return $sourceKey.'|'.$itemId.'|'.$field;
    }

    /**
     * @return array{0: int, 1: int} queued, skipped
     */
    private function requestForEntry(
        TranslationSource $source,
        TranslationEntry $entry,
        string $locale,
        bool $includeTranslated,
        ?string $requestedBy,
    ): array {
        $queued = 0;
        $skipped = 0;

        foreach ($entry->fields as $field) {
            $sourceText = $this->sourceTextFor($field);

            // Nothing to translate from. A field empty in the platform's own language
            // is a gap in the source rather than a translation task.
            if ($sourceText === null) {
                $skipped++;

                continue;
            }

            $existing = $field->valueFor($locale);
            $alreadyTranslated = is_string($existing) && trim($existing) !== '';

            if ($alreadyTranslated && ! $includeTranslated) {
                $skipped++;

                continue;
            }

            $created = $this->queueOne(
                $source->key(),
                $entry->id,
                $field->name,
                $locale,
                $sourceText,
                $existing,
                $requestedBy
            );

            $created ? $queued++ : $skipped++;
        }

        return [$queued, $skipped];
    }

    /**
     * The text to translate from: the platform's default language.
     *
     * Read from the field's own values rather than through a fallback, because a
     * fallback would translate one language's text while labelling it as another's.
     */
    private function sourceTextFor(TranslationField $field): ?string
    {
        $default = $this->defaultLocale();
        $value = $field->valueFor($default);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function defaultLocale(): string
    {
        return app(LocaleResolverInterface::class)->getDefaultLocale();
    }

    /**
     * Create the row and dispatch, unless one is already outstanding.
     */
    private function queueOne(
        string $sourceKey,
        string $itemId,
        string $field,
        string $locale,
        string $sourceText,
        ?string $existing,
        ?string $requestedBy,
    ): bool {
        $suggestion = DB::transaction(function () use (
            $sourceKey, $itemId, $field, $locale, $sourceText, $existing, $requestedBy
        ): ?TranslationSuggestion {
            /** @var TranslationSuggestion|null $held */
            $held = TranslationSuggestion::query()
                ->where('source_key', $sourceKey)
                ->where('item_id', $itemId)
                ->where('field', $field)
                ->where('locale', $locale)
                ->lockForUpdate()
                ->first();

            if ($held !== null && $held->status->isOutstanding()) {
                // Already asked and not yet answered or read. Pressing the button twice
                // must not cost twice.
                return null;
            }

            $attributes = [
                'requested_by' => $requestedBy,
                'status' => SuggestionStatus::PENDING,
                'source_text' => $sourceText,
                'existing_text' => $existing,
                'suggestion' => null,
                'error_code' => null,
                'error_message' => null,
                'completed_at' => null,
                'resolved_at' => null,
                'edited' => false,
            ];

            if ($held !== null) {
                // A resolved row is reused rather than accumulated: the unique
                // constraint holds one per field per language, and the history of what
                // was proposed is not what this table is for.
                $held->forceFill($attributes)->save();

                return $held;
            }

            return TranslationSuggestion::query()->create(array_merge($attributes, [
                'source_key' => $sourceKey,
                'item_id' => $itemId,
                'field' => $field,
                'locale' => $locale,
            ]));
        });

        if ($suggestion === null) {
            return false;
        }

        // After commit, so a worker cannot read a row the transaction has not written.
        DB::afterCommit(fn () => GenerateTranslationSuggestion::dispatch($suggestion->id));

        return true;
    }
}
