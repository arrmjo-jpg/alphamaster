<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Models;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One locale's human label for a role.
 */
class RoleTranslation extends BaseModel
{
    protected $table = 'role_translations';

    protected $fillable = [
        'role_id',
        'locale',
        'label',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
