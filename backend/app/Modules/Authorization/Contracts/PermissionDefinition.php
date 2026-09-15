<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Contracts;

/**
 * One administrative permission, as any module may declare it (ADR 0014, as extended by
 * ADR 0052).
 *
 * The platform's own permissions are the cases of `AdminPermission`. A module added later
 * declares its permissions as a backed enum implementing this interface and registers the
 * enum with `PermissionCatalogue`, so a new module's permissions are seeded, grantable and
 * given to super_admin without anyone editing the Authorization module.
 */
interface PermissionDefinition
{
    /**
     * The stored name: `{resource}.{action}`, lower case, at least two segments.
     */
    public function key(): string;

    /**
     * The module that owns the permission, recorded in its own column.
     */
    public function module(): string;
}
