<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One previous value of a non-secret setting (ADR 0040).
 *
 * Immutable: a revision describes a state that already happened, so there is no
 * `updated_at` column and the model refuses to be updated. Deleting is permitted —
 * revisions cascade with their setting, and a deployment may prune old ones against
 * the database, because unlike the audit trail nothing here is evidence.
 *
 * A secret setting never has a row here. That rule lives in the service that writes
 * revisions rather than being re-checked at every read, and is asserted by tests
 * against the whole registry.
 *
 * @property string $id
 * @property string $setting_id
 * @property int $version
 * @property string|null $locale
 * @property string|null $value
 * @property string|null $actor_id
 * @property Carbon $created_at
 *
 * @method static Builder|SettingRevision forSetting(string $settingId)
 */
class SettingRevision extends BaseModel
{
    protected $table = 'setting_revisions';

    /** A revision is written once and never revised. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'setting_id',
        'version',
        'locale',
        'value',
        'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('A setting revision records what already happened and cannot be updated.');
        });
    }

    /**
     * @return BelongsTo<Setting, $this>
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    /**
     * @param  Builder<SettingRevision>  $query
     * @return Builder<SettingRevision>
     */
    public function scopeForSetting(Builder $query, string $settingId): Builder
    {
        return $query->where('setting_id', $settingId);
    }
}
