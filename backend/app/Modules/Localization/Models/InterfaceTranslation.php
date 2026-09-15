<?php

declare(strict_types=1);

namespace App\Modules\Localization\Models;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * One operator's translation of one interface key into one language (ADR 0049).
 *
 * @property string $id
 * @property string $catalogue
 * @property string $locale
 * @property string $key
 * @property string $value
 * @property string $source_hash
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InterfaceTranslation extends BaseModel
{
    protected $table = 'interface_translations';

    protected $fillable = [
        'catalogue',
        'locale',
        'key',
        'value',
        'source_hash',
        'updated_by',
    ];
}
