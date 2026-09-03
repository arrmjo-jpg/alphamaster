<?php

declare(strict_types=1);

namespace App\Modules\Settings\Contracts;

use App\Modules\Settings\Exceptions\SettingGroupNotFoundException;

interface SettingServiceInterface
{
    /**
     * Get a typed setting value by key formatted as 'group.key'.
     *
     * Returns null for a provisioned setting whose stored value is NULL; $default is
     * returned only when the key does not exist.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set / update the value of an already provisioned setting.
     *
     * The stored type is authoritative and is never supplied by the caller.
     */
    public function set(string $group, string $key, mixed $value): void;

    /**
     * Batch update an array of settings within a group atomically.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function updateGroup(string $group, array $settings): array;

    /**
     * Retrieve all public settings grouped by group name for public API.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPublicSettings(): array;

    /**
     * Retrieve public settings for a specific group.
     *
     * @return array<string, mixed>
     *
     * @throws SettingGroupNotFoundException
     */
    public function getPublicGroup(string $group): array;

    /**
     * Retrieve all settings in a group for admin inspection (with secrets masked).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SettingGroupNotFoundException
     */
    public function getAdminGroup(string $group): array;

    /**
     * Retrieve all settings across all groups for admin inspection (with secrets masked).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getAdminAll(): array;

    /**
     * Invalidate cached settings.
     */
    public function clearCache(?string $group = null): void;
}
