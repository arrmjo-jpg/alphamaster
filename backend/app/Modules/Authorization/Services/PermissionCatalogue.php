<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Contracts\PermissionDefinition;
use App\Modules\Authorization\Enums\AdminPermission;
use BackedEnum;
use LogicException;

/**
 * Every administrative permission the running platform declares (ADR 0052).
 *
 * The seeder provisions from here, role validation accepts what is here, and super_admin
 * is granted all of it. A module contributes an enum from its own service provider:
 *
 *     $this->callAfterResolving(PermissionCatalogue::class,
 *         fn (PermissionCatalogue $catalogue) => $catalogue->register(CompetitionPermission::class));
 *
 * Enums rather than strings, so a permission is still never invented at a call site. Two
 * declarations of one name are refused: a permission that two modules believe they own is
 * a grant nobody can reason about.
 */
final class PermissionCatalogue
{
    private const KEY = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /** @var array<string, PermissionDefinition> */
    private array $permissions = [];

    public function __construct()
    {
        $this->register(AdminPermission::class);
    }

    /**
     * @param  class-string  $enum  a backed enum implementing PermissionDefinition
     */
    public function register(string $enum): void
    {
        if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class) || ! is_subclass_of($enum, PermissionDefinition::class)) {
            throw new LogicException("[{$enum}] must be a backed enum implementing ".PermissionDefinition::class.'.');
        }

        foreach ($enum::cases() as $permission) {
            /** @var PermissionDefinition $permission */
            $key = $permission->key();

            if (preg_match(self::KEY, $key) !== 1) {
                throw new LogicException("Permission [{$key}] must be lower-case {resource}.{action}.");
            }

            $existing = $this->permissions[$key] ?? null;

            if ($existing !== null && $existing !== $permission) {
                throw new LogicException("Permission [{$key}] is already declared by ".$existing::class.'.');
            }

            $this->permissions[$key] = $permission;
        }
    }

    /**
     * @return list<PermissionDefinition>
     */
    public function all(): array
    {
        return array_values($this->permissions);
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_keys($this->permissions);
    }

    public function has(string $key): bool
    {
        return isset($this->permissions[$key]);
    }
}
