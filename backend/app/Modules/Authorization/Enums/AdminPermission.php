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

    /**
     * Changing the platform's own defences, and supplying the credentials it uses.
     * Separate from settings.update because an administrator who may rename the site
     * is not thereby entitled to widen the login throttle or replace an API key.
     */
    /**
     * Restoring a group to an earlier state. Distinct from settings.update because it
     * changes many values at once, from a state the operator may not have inspected,
     * and it is the operation most likely to be run under pressure (ADR 0040).
     */
    case SETTINGS_ROLLBACK = 'settings.rollback';

    case SETTINGS_SECURITY_UPDATE = 'settings.security.update';
    case SETTINGS_SECRETS_MANAGE = 'settings.secrets.manage';

    /**
     * Reading the trail is its own permission: taken together it describes the
     * platform's security configuration and the habits of its administrators, and
     * performing an audited action is not a reason to be able to review everyone's
     * (ADR 0037).
     */
    case AUDIT_VIEW = 'audit.view';

    /**
     * Run an archival operation (ADR 0037, as extended).
     *
     * Reading the trail and removing from it are different powers, and holding the
     * first is not a reason to hold the second — the accounts most interested in
     * removal are the ones being recorded. Held by super_admin only, because
     * super_admin holds every permission explicitly by design; no other seeded role
     * carries it.
     */
    case AUDIT_MANAGE = 'audit.manage';

    /**
     * Export configuration, and restore it (ADR 0039).
     *
     * Exporting reads every non-secret value and lists which secrets exist; restoring
     * rewrites configuration wholesale. Neither is `settings.update`, and neither
     * should arrive with the permission to make an ordinary change.
     */
    case SETTINGS_BACKUP_MANAGE = 'settings.backup.manage';

    case ROLES_VIEW = 'roles.view';
    case ROLES_UPDATE = 'roles.update';

    case PERMISSIONS_VIEW = 'permissions.view';
    case PERMISSIONS_UPDATE = 'permissions.update';

    case INTEGRATIONS_VIEW = 'integrations.view';
    case INTEGRATIONS_UPDATE = 'integrations.update';

    case NOTIFICATIONS_VIEW = 'notifications.view';
    case NOTIFICATIONS_UPDATE = 'notifications.update';

    /**
     * Raise an announcement (ADR 0019).
     *
     * Separate from `notifications.update`, which is the power to change the wording
     * every recipient reads. Sending is a different act with a different blast radius:
     * an announcement reaches accounts, and an administrator entrusted with correcting
     * a typo in a template is not thereby entrusted with writing to everyone.
     */
    case NOTIFICATIONS_SEND = 'notifications.send';

    case MEDIA_VIEW = 'media.view';
    case MEDIA_DELETE = 'media.delete';

    /**
     * Ask the platform to generate something (ADR 0044).
     *
     * Its own permission because it is its own power: every request costs money on the
     * operator's account with the vendor, and an administrator entrusted with
     * correcting a translation is not thereby entrusted with spending on it.
     *
     * Distinct from configuring AI, which is `integrations.update` for the vendor and
     * `settings.update` for the model — the two already exist and already mean the
     * right thing, so this adds the one power neither of them covers.
     *
     * Not a permission to *apply* anything. AI proposes and a person accepts, and
     * accepting is the owning content's own write permission.
     */
    case AI_USE = 'ai.use';

    /**
     * The module that owns this permission.
     */
    public function module(): string
    {
        return match ($this) {
            self::USERS_VIEW, self::USERS_CREATE, self::USERS_UPDATE, self::USERS_DELETE => 'user',
            self::SETTINGS_VIEW, self::SETTINGS_UPDATE,
            self::SETTINGS_ROLLBACK, self::SETTINGS_SECURITY_UPDATE,
            self::SETTINGS_SECRETS_MANAGE, self::SETTINGS_BACKUP_MANAGE => 'settings',
            self::AUDIT_VIEW, self::AUDIT_MANAGE => 'core',
            self::ROLES_VIEW, self::ROLES_UPDATE,
            self::PERMISSIONS_VIEW, self::PERMISSIONS_UPDATE => 'authorization',
            self::INTEGRATIONS_VIEW, self::INTEGRATIONS_UPDATE => 'integration',
            self::NOTIFICATIONS_VIEW, self::NOTIFICATIONS_UPDATE,
            self::NOTIFICATIONS_SEND => 'notification',
            self::MEDIA_VIEW, self::MEDIA_DELETE => 'media',
            self::AI_USE => 'integration',
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
