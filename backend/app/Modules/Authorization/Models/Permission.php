<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * An administrative permission.
 *
 * Extends Spatie's model to carry the owning module (ADR 0014), so each module can
 * seed and query its own permissions without reaching into a global list.
 *
 * @property string $module
 *
 * @method static Builder|Permission forModule(string $module)
 */
class Permission extends SpatiePermission
{
    protected $fillable = [
        'name',
        'guard_name',
        'module',
    ];

    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }
}
