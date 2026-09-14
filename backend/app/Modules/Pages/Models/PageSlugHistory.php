<?php

declare(strict_types=1);

namespace App\Modules\Pages\Models;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A slug a published page used to have in one language, kept so the old address redirects
 * (ADR 0055 §6).
 *
 * @property string $id
 * @property string $page_id
 * @property string $locale
 * @property string $slug
 */
class PageSlugHistory extends BaseModel
{
    public const UPDATED_AT = null;

    protected $table = 'page_slug_history';

    /**
     * @var list<string>
     */
    protected $fillable = ['page_id', 'locale', 'slug'];

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }
}
