<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationField;
use App\Modules\Core\Translation\TranslationRefusedException;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Core\Translation\TranslationSource;
use App\Modules\Core\Translation\UnknownTranslationTargetException;
use App\Modules\Localization\Enums\BatchStatus;
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Models\TranslationBatch;
use App\Modules\Localization\Models\TranslationSuggestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A person's decision about one item's translation (ADR 0044 §5, ADR 0056).
 *
 * Accepted once, for the whole item. Every field is written in **one** call to the source's
 * own `write`, so a module that has a rule about its fields together — a notification template
 * refuses a subject without its body — is asked about the state the item will be in, not about
 * one field at a time on the way there.
 *
 * What is written is what the reviewer sent where they edited it and what was generated where
 * they did not. It goes through the same seam a typed translation takes, and leaves one audit
 * record for the item marked as coming from AI.
 */
class BatchAcceptance
{
    public function __construct(
        private readonly TranslationRegistry $registry,
        private readonly TranslationAudit $audit,
    ) {}

    /**
     * Accept one item's translation, with whatever the reviewer changed.
     *
     * @param  array<string, string|null>  $edits  field => the text the reviewer wants instead
     * @param  array<int, string>  $permissions
     * @param  TranslationEntry|null  $entry  the item as already read, when the caller has it
     *
     * @throws TranslationBatchRefusedException
     */
    public function accept(
        TranslationBatch $batch,
        array $edits,
        array $permissions,
        ?string $acceptedBy,
        ?TranslationEntry $entry = null,
    ): TranslationBatch {
        $source = $this->writableSource($batch, $permissions);

        if ($batch->status !== BatchStatus::READY) {
            throw TranslationBatchRefusedException::notReady($batch->status->value);
        }

        $entry ??= $this->entryOf($source, $batch->item_id);

        if ($entry === null) {
            throw TranslationBatchRefusedException::unknownTarget(
                UnknownTranslationTargetException::item($source->key(), $batch->item_id)
            );
        }

        /** @var Collection<string, TranslationSuggestion> $rows */
        $rows = TranslationSuggestion::query()->where('batch_id', $batch->getKey())->get()->keyBy('field');

        foreach (array_keys($edits) as $name) {
            if (! $rows->has($name)) {
                throw TranslationBatchRefusedException::unknownField((string) $name);
            }
        }

        /** @var array<string, TranslationField> $fields */
        $fields = [];

        foreach ($entry->fields as $field) {
            $fields[$field->name] = $field;
        }

        $before = [];
        $values = [];
        $edited = [];

        foreach ($rows as $name => $row) {
            $field = $fields[$name] ?? null;

            if ($field === null) {
                throw TranslationBatchRefusedException::unknownField((string) $name);
            }

            $current = $field->valueFor($batch->locale);

            // The guard that makes this safe. Every field was generated against what it held at
            // the time; if somebody wrote any of them while the item waited, accepting would
            // overwrite work nobody was shown.
            if ($row->targetMoved($current)) {
                throw TranslationBatchRefusedException::moved();
            }

            $proposed = array_key_exists($name, $edits) ? $edits[$name] : $row->suggestion;
            $text = is_string($proposed) && trim($proposed) !== '' ? $proposed : null;

            if ($text === null && $field->required) {
                throw TranslationBatchRefusedException::requiredEmpty((string) __($field->label));
            }

            if ($text !== null && $field->maxLength !== null && mb_strlen($text) > $field->maxLength) {
                throw TranslationBatchRefusedException::tooLong((string) __($field->label), mb_strlen($text), $field->maxLength);
            }

            // A reviewer's edit must keep what the source's placeholders stand for (ADR 0049).
            if ($text !== null && ! PlaceholderFidelity::preserved($row->source_text, $text)) {
                throw TranslationBatchRefusedException::placeholdersChanged((string) __($field->label));
            }

            if (trim((string) $text) !== trim((string) $row->suggestion)) {
                $edited[] = (string) $name;
            }

            // An optional field the reviewer cleared, over a target that was already empty,
            // changes nothing and is not written.
            if ($text === null && ! $field->hasValueFor($batch->locale)) {
                continue;
            }

            $before[$name] = $current;
            $values[$name] = $text;
        }

        DB::transaction(function () use ($batch, $source, $values, $rows, $edited, $acceptedBy): void {
            /** @var TranslationBatch|null $locked */
            $locked = TranslationBatch::query()->lockForUpdate()->find($batch->getKey());

            // Decided by somebody else between the read above and this lock.
            if ($locked === null || $locked->status !== BatchStatus::READY) {
                throw TranslationBatchRefusedException::notReady($locked === null ? 'missing' : $locked->status->value);
            }

            try {
                if ($values !== []) {
                    $source->write($batch->item_id, $batch->locale, $values);
                }
            } catch (UnknownTranslationTargetException $e) {
                throw TranslationBatchRefusedException::unknownTarget($e);
            } catch (TranslationRefusedException $e) {
                throw TranslationBatchRefusedException::refused($e);
            }

            foreach ($rows as $name => $row) {
                $row->forceFill([
                    'status' => SuggestionStatus::ACCEPTED,
                    'edited' => in_array((string) $name, $edited, true),
                    'accepted_by' => $acceptedBy,
                    'resolved_at' => now(),
                ])->save();
            }

            $locked->forceFill([
                'status' => BatchStatus::ACCEPTED,
                'edited' => $edited !== [],
                'accepted_by' => $acceptedBy,
                'resolved_at' => now(),
            ])->save();

            $batch->setRawAttributes($locked->getAttributes(), true);
        });

        $this->audit->record(
            $source->key(),
            $batch->item_id,
            $batch->locale,
            $this->audit->changedFields($before, $values),
            TranslationAudit::ORIGIN_AI,
            $edited !== []
        );

        return $batch;
    }

    /**
     * Accept every item ready for review in a language, item by item.
     *
     * One item refused — moved while it waited, rejected by its module — is reported and does
     * not stop the rest. Items the caller may not write are neither accepted nor reported:
     * naming them would describe content they were not granted.
     *
     * @param  array<int, string>  $permissions
     * @return array{accepted: int, failed: int, results: list<array{batch: string, source: string, item_id: string, status: string, error_code: string|null, message: string|null}>}
     */
    public function acceptReady(string $locale, ?string $sourceKey, array $permissions, ?string $acceptedBy): array
    {
        $batches = TranslationBatch::query()
            ->where('locale', $locale)
            ->where('status', BatchStatus::READY->value)
            ->when($sourceKey !== null, fn ($query) => $query->where('source_key', $sourceKey))
            ->orderBy('source_key')
            ->orderBy('item_id')
            ->get();

        /** @var array<string, array<string, TranslationEntry>> $entries */
        $entries = [];
        $accepted = 0;
        $failed = 0;
        $results = [];

        foreach ($batches as $batch) {
            $source = $this->registry->find($batch->source_key);

            if ($source === null || ! in_array($source->writePermission(), $permissions, true)) {
                continue;
            }

            // Read once per source rather than once per item: accepting one item does not
            // change another's, so the snapshot stays valid for the rest of the loop.
            $entries[$batch->source_key] ??= $this->entriesById($source);

            $result = [
                'batch' => (string) $batch->getKey(),
                'source' => $batch->source_key,
                'item_id' => $batch->item_id,
            ];

            try {
                $this->accept($batch, [], $permissions, $acceptedBy, $entries[$batch->source_key][$batch->item_id] ?? null);
                $accepted++;
                $results[] = $result + ['status' => 'accepted', 'error_code' => null, 'message' => null];
            } catch (TranslationBatchRefusedException $e) {
                $failed++;
                $results[] = $result + ['status' => 'failed', 'error_code' => $e->errorCode, 'message' => $e->reason()];
            } catch (Throwable $e) {
                // A fault in one module's write is that item's failure, not the operator's
                // whole request. It is still reported, so it is not lost.
                report($e);
                $failed++;
                $results[] = $result + [
                    'status' => 'failed',
                    'error_code' => 'ACCEPT_FAILED',
                    'message' => (string) __('api.error.translations.accept_failed'),
                ];
            }
        }

        return ['accepted' => $accepted, 'failed' => $failed, 'results' => $results];
    }

    /**
     * Discard an item's translation without writing anything.
     *
     * @param  array<int, string>  $permissions
     *
     * @throws TranslationBatchRefusedException
     */
    public function dismiss(TranslationBatch $batch, array $permissions): TranslationBatch
    {
        $this->writableSource($batch, $permissions);

        DB::transaction(function () use ($batch): void {
            TranslationSuggestion::query()
                ->where('batch_id', $batch->getKey())
                ->whereIn('status', [SuggestionStatus::PENDING->value, SuggestionStatus::READY->value, SuggestionStatus::FAILED->value])
                ->update(['status' => SuggestionStatus::DISMISSED->value, 'resolved_at' => now()]);

            $batch->forceFill([
                'status' => BatchStatus::DISMISSED,
                'resolved_at' => now(),
            ])->save();
        });

        return $batch;
    }

    /**
     * @param  array<int, string>  $permissions
     *
     * @throws TranslationBatchRefusedException
     */
    private function writableSource(TranslationBatch $batch, array $permissions): TranslationSource
    {
        $source = $this->registry->find($batch->source_key);

        if ($source === null) {
            throw TranslationBatchRefusedException::unknownSource($batch->source_key);
        }

        if (! in_array($source->writePermission(), $permissions, true)) {
            throw TranslationBatchRefusedException::forbidden($batch->source_key);
        }

        return $source;
    }

    private function entryOf(TranslationSource $source, string $id): ?TranslationEntry
    {
        return $this->entriesById($source)[$id] ?? null;
    }

    /**
     * @return array<string, TranslationEntry>
     */
    private function entriesById(TranslationSource $source): array
    {
        $byId = [];

        foreach ($source->entries() as $entry) {
            $byId[$entry->id] = $entry;
        }

        return $byId;
    }
}
