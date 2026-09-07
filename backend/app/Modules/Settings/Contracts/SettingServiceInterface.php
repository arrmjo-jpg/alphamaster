<?php

declare(strict_types=1);

namespace App\Modules\Settings\Contracts;

use App\Modules\Settings\Exceptions\SettingGroupNotFoundException;
use App\Modules\Settings\Exceptions\UnknownRevisionException;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use App\Modules\Settings\Rollback\RollbackPlan;

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
    /**
     * An opaque validator for a group's current state (ADR 0038).
     *
     * Returned with a read and required on a write, so an update built on a stale
     * read is refused rather than silently discarding someone else's change.
     */
    /**
     * What the settings in a group used to be, newest first (ADR 0040).
     *
     * Secrets contribute nothing: they have no revisions, so nothing here hints at
     * what a credential was.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groupHistory(string $group, ?string $key = null, int $limit = 100): array;

    /**
     * Replace a stored credential (ADR 0038, as extended).
     *
     * Verification happens before this is called and its outcome travels into the
     * audit trail, so the trail distinguishes a rotation that was confirmed with the
     * vendor from one nobody could confirm. No revision is written: a secret has no
     * history by design (ADR 0040).
     *
     * @throws UnknownSettingKeyException when nothing by that name is a secret here
     */
    public function rotateSecret(string $group, string $key, string $candidate, string $verification): void;

    public function groupVersion(string $group): string;

    /**
     * Decide what rolling a group back to a point in its history would do (ADR 0040).
     *
     * Writes nothing. The target names a revision rather than a version, because a
     * version counts writes to a single row and a group has one counter per setting.
     *
     * @throws SettingGroupNotFoundException
     * @throws UnknownRevisionException when the target is unknown or belongs elsewhere
     */
    public function planRollback(string $group, string $revisionId): RollbackPlan;

    /**
     * Apply a plan, transactionally, as a new change rather than a rewrite (ADR 0040).
     *
     * Takes the plan it was given rather than recomputing one, because the caller
     * authorized that plan and re-deriving it would apply something nobody approved.
     */
    public function applyRollback(RollbackPlan $plan): void;

    public function clearCache(?string $group = null): void;
}
