<?php

declare(strict_types=1);

namespace App\Modules\Localization\Models;

use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Core\Models\BaseModel;
use App\Modules\Localization\Enums\LanguageDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $native_name
 * @property LanguageDirection $direction
 * @property bool $is_active
 * @property bool $is_default
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|Language active()
 * @method static Builder|Language default()
 * @method static Builder|Language ordered()
 */
class Language extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'languages';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'native_name',
        'direction',
        'is_active',
        'is_default',
        'sort_order',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::saved(function (): void {
            if (app()->bound(LocaleResolverInterface::class)) {
                app(LocaleResolverInterface::class)->clearCache();
            }
        });

        static::deleted(function (): void {
            if (app()->bound(LocaleResolverInterface::class)) {
                app(LocaleResolverInterface::class)->clearCache();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'direction' => LanguageDirection::class,
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ]);
    }

    /**
     * Scope a query to only include active languages.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to find the default language.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to sort languages by sort order then name.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('name', 'asc');
    }

    /**
     * Determine if the language is right-to-left.
     */
    public function isRtl(): bool
    {
        return $this->direction === LanguageDirection::RTL;
    }

    /**
     * Determine if the language is left-to-right.
     */
    public function isLtr(): bool
    {
        return $this->direction === LanguageDirection::LTR;
    }
}
