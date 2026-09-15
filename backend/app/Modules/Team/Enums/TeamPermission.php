<?php

declare(strict_types=1);

namespace App\Modules\Team\Enums;

use App\Modules\Authorization\Contracts\PermissionDefinition;

/**
 * The permissions the Team module declares (ADR 0052, ADR 0055 §10).
 */
enum TeamPermission: string implements PermissionDefinition
{
    case VIEW = 'team.view';
    case CREATE = 'team.create';
    case UPDATE = 'team.update';
    case DELETE = 'team.delete';

    public function key(): string
    {
        return $this->value;
    }

    public function module(): string
    {
        return 'team';
    }
}
