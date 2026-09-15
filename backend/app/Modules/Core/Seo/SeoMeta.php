<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One language's search and sharing metadata for any model (ADR 0032).
 *
 * @property string $id
 * @property string $seoable_type
 * @property string $seoable_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $description
 * @property string|null $robots
 * @property string|null $canonical_url
 * @property string|null $og_title
 * @property string|null $og_description
 * @property string|null $og_media_id
 */
class SeoMeta extends BaseModel
{
    protected $table = 'seo_meta';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'seoable_type', 'seoable_id', 'locale',
        'title', 'description', 'robots', 'canonical_url',
        'og_title', 'og_description', 'og_media_id',
    ];

    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }

    public function fields(): SeoFields
    {
        return SeoFields::fromArray($this->only(SeoFields::FIELDS));
    }
}
