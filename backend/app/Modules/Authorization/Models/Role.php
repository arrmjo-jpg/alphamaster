<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * An administrative role.
 *
 * Roles exist solely to group admin permissions. Holding one is never what makes an
 * account an administrator — that is decided by account_type alone — so a role
 * relation on a regular user grants nothing.
 */
class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'guard_name',
    ];
}
