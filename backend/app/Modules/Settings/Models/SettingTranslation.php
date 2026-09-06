<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One locale's value for a localized setting.
 *
 * Never holds a secret: a setting cannot be both secret and localized, enforced by
 * the definition, the model and a database constraint (ADR 0018). So nothing here
 * is encrypted, and nothing here needs to be.
 *
 * @property string $id
 * @property string $setting_id
 * @property string $locale
 * @property string|null $value
 */
class SettingTranslation extends BaseModel
{
    protected $table = 'setting_translations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'setting_id',
        'locale',
        'value',
    ];

    /**
     * @return BelongsTo<Setting, $this>
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }
}
