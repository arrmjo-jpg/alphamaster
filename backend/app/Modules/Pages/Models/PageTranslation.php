<?php

declare(strict_types=1);

namespace App\Modules\Pages\Models;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One language's text and address for a page.
 *
 * @property string $id
 * @property string $page_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $slug
 * @property string|null $summary
 * @property string|null $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PageTranslation extends BaseModel
{
    protected $table = 'page_translations';

    /**
     * @var list<string>
     */
    protected $fillable = ['page_id', 'locale', 'title', 'slug', 'summary', 'body'];

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }
}
