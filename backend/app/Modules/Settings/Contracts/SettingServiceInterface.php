<?php

declare(strict_types=1);

namespace App\Modules\Settings\Contracts;

interface SettingServiceInterface
{
    /**
     * Get a typed setting value by key formatted as 'group.key'.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set / update a setting value.
     */
    public function set(string $group, string $key, mixed $value, ?string $type = null): void;

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
     */
    public function getPublicGroup(string $group): array;

    /**
     * Retrieve all settings in a group for admin inspection (with secrets masked).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminGroup(string $group): array;

    /**
     * Retrieve all settings across all groups for admin inspection (with secrets masked).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getAdminAll(): array;

    /**
     * Invalidate cached settings in Redis.
     */
    public function clearCache(?string $group = null): void;
}
