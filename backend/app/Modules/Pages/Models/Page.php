<?php

declare(strict_types=1);

namespace App\Modules\Pages\Models;

use App\Modules\Core\Concerns\HasTranslations;
use App\Modules\Core\Models\BaseModel;
use App\Modules\Pages\Enums\PageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A static page: one record, whatever languages it is written in (ADR 0055 §2).
 *
 * @property string $id
 * @property PageStatus $status
 * @property Carbon|null $published_at
 * @property int $sort_order
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @use HasTranslations<PageTranslation>
 */
class Page extends BaseModel
{
    /** @use HasTranslations<PageTranslation> */
    use HasTranslations;

    protected $table = 'pages';

    /**
     * @var list<string>
     */
    protected $fillable = ['status', 'published_at', 'sort_order', 'created_by', 'updated_by'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => PageStatus::class,
            'published_at' => 'datetime',
            'sort_order' => 'integer',
        ]);
    }

    public function translationModel(): string
    {
        return PageTranslation::class;
    }

    public function translatableAttributes(): array
    {
        return ['title', 'slug', 'summary', 'body'];
    }

    /**
     * @return HasMany<PageSlugHistory, $this>
     */
    public function slugHistory(): HasMany
    {
        return $this->hasMany(PageSlugHistory::class, 'page_id');
    }

    /**
     * Published, and its publication time has come.
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where('status', PageStatus::PUBLISHED->value)
            ->where(static fn (Builder $inner) => $inner->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function isLive(): bool
    {
        return $this->status === PageStatus::PUBLISHED
            && ($this->published_at === null || $this->published_at->lessThanOrEqualTo(now()));
    }
}
