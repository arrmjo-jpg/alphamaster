<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Translation\TranslationSource;

/**
 * Records that content was translated (ADR 0048 §6).
 *
 * One place, used by the workshop's save and by accepting a suggestion, so a
 * translation typed by hand and one accepted from a model leave the same kind of
 * record and differ only in what they say about their origin.
 *
 * What it records is shape, never text: which fields changed, in which language, of
 * which item, and whether a person wrote it or accepted a suggestion. The wording
 * stays in the content. A trail that copied it would be a second, unversioned copy of
 * every translation, readable by anyone with `audit.view` (ADR 0037).
 */
class TranslationAudit
{
    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_AI = 'ai';

    public function __construct(private readonly AuditRecorderContract $audit) {}

    /**
     * What an item's fields hold in a language right now, read through its source.
     *
     * Taken before a write so the record can name what actually moved. An item the
     * source does not know yields nothing, and the write that follows is refused by
     * the source itself.
     *
     * @return array<string, string|null>
     */
    public function snapshot(TranslationSource $source, string $id, string $locale): array
    {
        foreach ($source->entries() as $entry) {
            if ($entry->id !== $id) {
                continue;
            }

            $values = [];

            foreach ($entry->fields as $field) {
                $values[$field->name] = $field->valueFor($locale);
            }

            return $values;
        }

        return [];
    }

    /**
     * The fields whose text a write changes, compared the way the workshop compares.
     *
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $written
     * @return array<int, string>
     */
    public function changedFields(array $before, array $written): array
    {
        $changed = [];

        foreach ($written as $name => $value) {
            if ($this->normalise($before[$name] ?? null) !== $this->normalise($value)) {
                $changed[] = (string) $name;
            }
        }

        return $changed;
    }

    /**
     * Record a translation, if anything changed.
     *
     * @param  array<int, string>  $fields
     */
    public function record(
        string $sourceKey,
        string $itemId,
        string $locale,
        array $fields,
        string $origin,
        ?bool $edited = null,
    ): void {
        // A save that moved nothing is not an operation on the platform (ADR 0037).
        if ($fields === []) {
            return;
        }

        $context = [
            'source' => $sourceKey,
            'item' => $itemId,
            'locale' => $locale,
            'fields' => array_values($fields),
            'origin' => $origin,
        ];

        if ($edited !== null) {
            $context['edited'] = $edited;
        }

        $this->audit->succeeded(AuditAction::TRANSLATION_UPDATED, $sourceKey.'/'.$itemId, $context);
    }

    private function normalise(?string $value): string
    {
        return trim((string) $value);
    }
}
