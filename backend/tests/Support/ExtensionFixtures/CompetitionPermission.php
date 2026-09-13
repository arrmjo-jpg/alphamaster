<?php

declare(strict_types=1);

namespace Tests\Support\ExtensionFixtures;

use App\Modules\Authorization\Contracts\PermissionDefinition;

/**
 * Permissions as a future domain module would declare them (ADR 0052).
 */
enum CompetitionPermission: string implements PermissionDefinition
{
    case VIEW = 'competitions.view';

    case PUBLISH = 'competitions.publish';

    public function key(): string
    {
        return $this->value;
    }

    public function module(): string
    {
        return 'competitions';
    }
}
