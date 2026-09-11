<?php

declare(strict_types=1);

namespace App\Modules\Localization\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\Localization\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * One proposed translation of one field into one language.
 *
 * @property string $id
 * @property string|null $requested_by
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
            'completed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'edited' => 'boolean',
        ]);
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
     * The guard that makes accepting safe. A suggestion is generated against what the
     * field held at the time; if somebody wrote a translation in between, applying the
     * suggestion would overwrite work nobody was shown. Compared as text rather than
     * by a version counter because the workshop writes through several modules' own
     * update paths, and none of them shares one.
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
