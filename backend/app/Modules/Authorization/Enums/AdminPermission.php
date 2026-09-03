<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Enums;

/**
 * The administrative permission catalogue.
 *
 * Two-segment keys, {resource}.{action}, with the owning module recorded in its own
 * column rather than folded into the name. Enumerating them here means a permission
 * string is never invented at a call site and typos fail at the type level.
 */
enum AdminPermission: string
{
    case USERS_VIEW = 'users.view';
    case USERS_CREATE = 'users.create';
    case USERS_UPDATE = 'users.update';
    case USERS_DELETE = 'users.delete';

    case SETTINGS_VIEW = 'settings.view';
    case SETTINGS_UPDATE = 'settings.update';

    case ROLES_VIEW = 'roles.view';
    case ROLES_UPDATE = 'roles.update';

    case PERMISSIONS_VIEW = 'permissions.view';
    case PERMISSIONS_UPDATE = 'permissions.update';

    case INTEGRATIONS_VIEW = 'integrations.view';
    case INTEGRATIONS_UPDATE = 'integrations.update';

    case NOTIFICATIONS_VIEW = 'notifications.view';
    case NOTIFICATIONS_UPDATE = 'notifications.update';

    /**
     * The module that owns this permission.
     */
    public function module(): string
    {
        return match ($this) {
            self::USERS_VIEW, self::USERS_CREATE, self::USERS_UPDATE, self::USERS_DELETE => 'user',
            self::SETTINGS_VIEW, self::SETTINGS_UPDATE => 'settings',
            self::ROLES_VIEW, self::ROLES_UPDATE,
            self::PERMISSIONS_VIEW, self::PERMISSIONS_UPDATE => 'authorization',
            self::INTEGRATIONS_VIEW, self::INTEGRATIONS_UPDATE => 'integration',
            self::NOTIFICATIONS_VIEW, self::NOTIFICATIONS_UPDATE => 'notification',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
