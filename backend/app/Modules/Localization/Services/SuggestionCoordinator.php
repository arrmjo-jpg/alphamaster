<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

use App\Modules\Core\Ai\TextGeneratorContract;
use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Core\Translation\DeclaresSourceLocale;
use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationField;
use App\Modules\Core\Translation\TranslationItemStatus;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Core\Translation\TranslationSource;
use App\Modules\Localization\Enums\BatchStatus;
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Jobs\GenerateTranslationSuggestion;
use App\Modules\Localization\Models\TranslationBatch;
use App\Modules\Localization\Models\TranslationSuggestion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns "translate this item" and "translate all missing" into batches and queued work
 * (ADR 0056).
 *
 * The item is what is asked for. Which of its fields are sent is read from the fields'
 * metadata — translatable, with text in the default language, and untranslated in the target —
 * and never from their names, so a module registered tomorrow is translated the same way
 * without anything here learning about it.
 *
 * The decisions worth reading are about what it refuses to do.
 *
 * **It never starts a second batch for an item that has one pending or waiting for review.**
 * Pressing Translate twice returns the batch already there; it does not bill twice, and it
 * does not give a reviewer two answers for one item.
 *
 * **"All missing" means the items that are not translated.** An item whose required fields all
 * have text is translated, and an empty optional field does not make it a task. Naming one item
 * is how an operator asks for its optional fields as well.
 *
 * **It records what the target held.** Each field's snapshot is what makes accepting safe: a
 * translation generated against an empty field cannot quietly overwrite one written while it
 * was queued.
 */
class SuggestionCoordinator
{
    public const QUEUED = 'queued';

    public const EXISTING = 'existing';

    public const SKIPPED = 'skipped';

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
     * Start batches for one locale: every item that needs one, or one source, or one item.
     *
     * @param  array<int, string>  $permissions  what the requester holds
     * @param  string|null  $sourceKey  narrow to one body of content, or null for all
     * @param  string|null  $itemId  narrow to one item, or null for the whole source
     * @return array{queued: int, existing: int, skipped: int}
     */
    public function request(
        string $locale,
        array $permissions,
        ?string $sourceKey = null,
        ?string $itemId = null,
        bool $includeTranslated = false,
        ?string $requestedBy = null,
    ): array {
        $counts = [self::QUEUED => 0, self::EXISTING => 0, self::SKIPPED => 0];
        $from = $this->defaultLocale();

        foreach ($this->registry->all() as $key => $source) {
            if ($sourceKey !== null && $key !== $sourceKey) {
                continue;
            }

            // The owning module's write permission, asked exactly as the workshop asks it.
            // Translating content the requester may not write would spend money to produce
            // something they cannot use, and hand them source text they were not granted.
            if (! in_array($source->writePermission(), $permissions, true)) {
                continue;
            }

            foreach ($source->entries() as $entry) {
                if ($itemId !== null && $entry->id !== $itemId) {
                    continue;
                }

                $counts[$this->requestForEntry($source, $entry, $this->sourceLocaleOf($source, $from), $locale, $itemId !== null, $includeTranslated, $requestedBy)]++;
            }
        }

        return $counts;
    }

    /**
     * The batches still open in a locale — pending, ready or failed — keyed by the workshop's
     * address for an item, with their fields.
     *
     * @return array<string, TranslationBatch>
     */
    public function openBatches(string $locale): array
    {
        $keyed = [];

        $batches = TranslationBatch::query()
            ->where('locale', $locale)
            ->open()
            ->with('suggestions')
            ->get();

        foreach ($batches as $batch) {
            $keyed[$this->addressOf($batch->source_key, $batch->item_id)] = $batch;
        }

        return $keyed;
    }

    /**
     * The workshop's address for an item, as one string.
     */
    public function addressOf(string $sourceKey, string $itemId): string
    {
        return $sourceKey.'|'.$itemId;
    }

    /**
     * The fields of an item that would be sent, each with the text it is translated from.
     *
     * Read from the fields' own values rather than through a fallback, because a fallback
     * would translate one language's text while labelling it as another's.
     *
     * @return list<array{0: TranslationField, 1: string}>
     */
    public function fieldsToTranslate(TranslationEntry $entry, string $from, string $into, bool $includeTranslated): array
    {
        $fields = [];

        foreach ($entry->translatableFields() as $field) {
            $sourceText = $field->valueFor($from);

            // Nothing to translate from. A field empty in the platform's own language is a
            // gap in the source rather than a translation task.
            if (! is_string($sourceText) || trim($sourceText) === '') {
                continue;
            }

            if ($field->hasValueFor($into) && ! $includeTranslated) {
                continue;
            }

            $fields[] = [$field, $sourceText];
        }

        return $fields;
    }

    /**
     * The language a source is translated from: its own, where it declares one (ADR 0049), and
     * the platform's default otherwise.
     */
    public function sourceLocaleOf(TranslationSource $source, ?string $default = null): ?string
    {
        if ($source instanceof DeclaresSourceLocale) {
            return $source->sourceLocale();
        }

        return $default ?? $this->defaultLocale();
    }

    private function requestForEntry(
        TranslationSource $source,
        TranslationEntry $entry,
        ?string $from,
        string $into,
        bool $named,
        bool $includeTranslated,
        ?string $requestedBy,
    ): string {
        // Nothing is translated into the language it is written in.
        if ($from === null || $from === $into) {
            return self::SKIPPED;
        }

        if (! $named && ! $includeTranslated && $entry->statusIn($into) === TranslationItemStatus::TRANSLATED) {
            return self::SKIPPED;
        }

        $fields = $this->fieldsToTranslate($entry, $from, $into, $includeTranslated);

        if ($fields === []) {
            return self::SKIPPED;
        }

        return $this->queueBatch($source->key(), $entry->id, $into, $fields, $requestedBy);
    }

    /**
     * Start the item's batch, unless one is already under way or waiting for review.
     *
     * A failed batch is retried rather than replaced wholesale: the fields that came back and
     * still match what they were generated against are kept, and only the rest are asked for
     * again — a retry should not pay twice for text that already arrived.
     *
     * @param  list<array{0: TranslationField, 1: string}>  $fields
     */
    private function queueBatch(string $sourceKey, string $itemId, string $locale, array $fields, ?string $requestedBy): string
    {
        try {
            /** @var array{0: string, 1: TranslationBatch, 2: list<string>} $result */
            $result = DB::transaction(function () use ($sourceKey, $itemId, $locale, $fields, $requestedBy): array {
                /** @var TranslationBatch|null $batch */
                $batch = TranslationBatch::query()
                    ->where('source_key', $sourceKey)
                    ->where('item_id', $itemId)
                    ->where('locale', $locale)
                    ->lockForUpdate()
                    ->first();

                if ($batch !== null && $batch->status->isOutstanding()) {
                    return [self::EXISTING, $batch, []];
                }

                $kept = $batch !== null && $batch->status === BatchStatus::FAILED
                    ? $this->reusable($batch, $fields, $locale)
                    : [];

                $batch ??= new TranslationBatch;

                $batch->forceFill([
                    'source_key' => $sourceKey,
                    'item_id' => $itemId,
                    'locale' => $locale,
                    'requested_by' => $requestedBy,
                    'accepted_by' => null,
                    'status' => BatchStatus::PENDING,
                    'fields_total' => count($fields),
                    'fields_ready' => count($kept),
                    'fields_failed' => 0,
                    'error_code' => null,
                    'error_message' => null,
                    'edited' => false,
                    'completed_at' => null,
                    'resolved_at' => null,
                ])->save();

                // One row per field per language, so whatever a previous batch left for this
                // item goes before the new fields are written.
                TranslationSuggestion::query()
                    ->where('source_key', $sourceKey)
                    ->where('item_id', $itemId)
                    ->where('locale', $locale)
                    ->whereNotIn('field', $kept)
                    ->delete();

                $queued = [];

                foreach ($fields as [$field, $sourceText]) {
                    if (in_array($field->name, $kept, true)) {
                        continue;
                    }

                    $row = TranslationSuggestion::query()->create([
                        'batch_id' => $batch->getKey(),
                        'requested_by' => $requestedBy,
                        'source_key' => $sourceKey,
                        'item_id' => $itemId,
                        'field' => $field->name,
                        'locale' => $locale,
                        'status' => SuggestionStatus::PENDING,
                        'source_text' => $sourceText,
                        'existing_text' => $field->valueFor($locale),
                        'suggestion' => null,
                        'field_label' => $field->label,
                        'field_type' => $field->type,
                        'field_group' => $field->group,
                        'required' => $field->required,
                        'max_length' => $field->maxLength,
                        'edited' => false,
                    ]);

                    $queued[] = (string) $row->getKey();
                }

                return [self::QUEUED, $batch, $queued];
            });
        } catch (UniqueConstraintViolationException) {
            // Another request created this item's batch between the read and the write. It
            // is the same batch this one would have been.
            return self::EXISTING;
        }

        [$outcome, $batch, $queued] = $result;

        if ($outcome === self::QUEUED && $queued === []) {
            // Every field was kept from the failed attempt, so the batch is already whole.
            $batch->refreshFromFields();
        }

        foreach ($queued as $id) {
            // After commit, so a worker cannot read a row the transaction has not written.
            DB::afterCommit(fn () => GenerateTranslationSuggestion::dispatch($id));
        }

        return $outcome;
    }

    /**
     * The fields of a failed batch whose text came back and is still valid.
     *
     * @param  list<array{0: TranslationField, 1: string}>  $fields
     * @return list<string>
     */
    private function reusable(TranslationBatch $batch, array $fields, string $locale): array
    {
        $wanted = [];

        foreach ($fields as [$field, $sourceText]) {
            $wanted[$field->name] = [$sourceText, $field->valueFor($locale)];
        }

        $kept = [];

        foreach ($batch->suggestions as $row) {
            if ($row->status !== SuggestionStatus::READY || ! isset($wanted[$row->field])) {
                continue;
            }

            [$sourceText, $existing] = $wanted[$row->field];

            if ($row->source_text === $sourceText && ! $row->targetMoved($existing)) {
                $kept[] = $row->field;
            }
        }

        return $kept;
    }

    private function defaultLocale(): string
    {
        return app(LocaleResolverInterface::class)->getDefaultLocale();
    }
}
