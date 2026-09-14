<?php

declare(strict_types=1);

namespace App\Modules\Localization\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\Core\Translation\FieldGroup;
use App\Modules\Core\Translation\FieldType;
use App\Modules\Localization\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One field of a translation batch: what was generated for it, and why not if nothing was.
 *
 * The unit a job works on, never the unit a person decides on (ADR 0056). It carries the
 * field's metadata as its source described it at request time, so the job can choose how to
 * translate it without reading the source again.
 *
 * @property string $id
 * @property string|null $batch_id
 * @property string|null $requested_by
 * @property string|null $accepted_by
 * @property string $source_key
 * @property string $item_id
 * @property string $field
 * @property string $locale
 * @property SuggestionStatus $status
 * @property string $source_text
 * @property string|null $existing_text
 * @property string|null $suggestion
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $field_label
 * @property FieldType $field_type
 * @property FieldGroup $field_group
 * @property bool $required
 * @property int|null $max_length
 * @property Carbon|null $completed_at
 * @property Carbon|null $resolved_at
 * @property bool $edited
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|TranslationSuggestion outstanding()
 */
class TranslationSuggestion extends BaseModel
{
    protected $table = 'translation_suggestions';

    protected $fillable = [
        'batch_id',
        'requested_by',
        'source_key',
        'item_id',
        'field',
        'locale',
        'status',
        'source_text',
        'existing_text',
        'suggestion',
        'error_code',
        'error_message',
        'field_label',
        'field_type',
        'field_group',
        'required',
        'max_length',
        'completed_at',
        'resolved_at',
        'edited',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => SuggestionStatus::class,
            'field_type' => FieldType::class,
            'field_group' => FieldGroup::class,
            'required' => 'boolean',
            'max_length' => 'integer',
            'completed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'edited' => 'boolean',
        ]);
    }

    /**
     * @return BelongsTo<TranslationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(TranslationBatch::class, 'batch_id');
    }

    /**
     * Still awaiting a decision — queued, or answered and unread.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SuggestionStatus::PENDING->value,
            SuggestionStatus::READY->value,
        ]);
    }

    /**
     * Whether the target has moved since this was proposed.
     *
     * The guard that makes accepting safe. A suggestion is generated against what the field
     * held at the time; if somebody wrote a translation in between, applying the suggestion
     * would overwrite work nobody was shown. Compared as text rather than by a version counter
     * because the workshop writes through several modules' own update paths, and none of them
     * shares one.
     */
    public function targetMoved(?string $current): bool
    {
        return $this->normalise($current) !== $this->normalise($this->existing_text);
    }

    private function normalise(?string $value): string
    {
        return trim((string) $value);
    }
}
