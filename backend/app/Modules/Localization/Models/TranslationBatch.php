<?php

declare(strict_types=1);

namespace App\Modules\Localization\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\Localization\Enums\BatchStatus;
use App\Modules\Localization\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The translation of one item into one language (ADR 0056).
 *
 * @property string $id
 * @property string|null $requested_by
 * @property string|null $accepted_by
 * @property string $source_key
 * @property string $item_id
 * @property string $locale
 * @property BatchStatus $status
 * @property int $fields_total
 * @property int $fields_ready
 * @property int $fields_failed
 * @property string|null $error_code
 * @property string|null $error_message
 * @property bool $edited
 * @property Carbon|null $completed_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, TranslationSuggestion> $suggestions
 *
 * @method static Builder|TranslationBatch open()
 */
class TranslationBatch extends BaseModel
{
    protected $table = 'translation_batches';

    protected $fillable = [
        'requested_by',
        'accepted_by',
        'source_key',
        'item_id',
        'locale',
        'status',
        'fields_total',
        'fields_ready',
        'fields_failed',
        'error_code',
        'error_message',
        'edited',
        'completed_at',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => BatchStatus::class,
            'fields_total' => 'integer',
            'fields_ready' => 'integer',
            'fields_failed' => 'integer',
            'edited' => 'boolean',
            'completed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ]);
    }

    /**
     * @return HasMany<TranslationSuggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(TranslationSuggestion::class, 'batch_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * Pending, ready or failed: what the workshop still has to show.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', BatchStatus::openValues());
    }

    /**
     * Recount the fields and move the batch to the state they add up to.
     *
     * Under a lock on the batch, because each field's job finishes on its own and two finishing
     * together would otherwise each count the other as still pending — and whichever wrote last
     * would decide, rightly or not. A batch somebody already decided is left as it is.
     */
    public function refreshFromFields(): void
    {
        DB::transaction(function (): void {
            /** @var TranslationBatch|null $locked */
            $locked = self::query()->lockForUpdate()->find($this->getKey());

            if ($locked === null || ! $locked->status->isOpen()) {
                return;
            }

            $rows = TranslationSuggestion::query()->where('batch_id', $locked->getKey())->get();

            $pending = $rows->filter(fn (TranslationSuggestion $row): bool => $row->status === SuggestionStatus::PENDING)->count();
            $ready = $rows->filter(fn (TranslationSuggestion $row): bool => $row->status === SuggestionStatus::READY)->count();

            /** @var TranslationSuggestion|null $failed */
            $failed = $rows->first(fn (TranslationSuggestion $row): bool => $row->status === SuggestionStatus::FAILED);
            $failures = $rows->filter(fn (TranslationSuggestion $row): bool => $row->status === SuggestionStatus::FAILED)->count();

            $status = match (true) {
                $pending > 0 => BatchStatus::PENDING,
                $failures > 0 => BatchStatus::FAILED,
                default => BatchStatus::READY,
            };

            $locked->forceFill([
                'status' => $status,
                'fields_total' => $rows->count(),
                'fields_ready' => $ready,
                'fields_failed' => $failures,
                'error_code' => $failed?->error_code,
                'error_message' => $failed?->error_message,
                'completed_at' => $pending > 0 ? null : now(),
            ])->save();

            $this->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
