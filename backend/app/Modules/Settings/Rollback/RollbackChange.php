<?php

declare(strict_types=1);

namespace App\Modules\Settings\Rollback;

/**
 * One value a rollback will restore (ADR 0040).
 *
 * The value is the canonical stored string, not a cast one: it came out of the
 * revision store in that form and goes back into the settings table in that form, so
 * casting it in between would mean round-tripping through PHP types for no reason and
 * risking a value that does not survive the trip.
 *
 * `locale` is null for a setting that is not localized. For one that is, this is the
 * language this value belongs to — a localized setting contributes one change per
 * language whose history moved, and leaves the others alone.
 */
final class RollbackChange
{
    /**
     * @param  string|null  $value  the canonical stored form, written as-is
     * @param  mixed  $typed  the same value cast through the type the setting is
     *                        declared with today, for reporting rather than for storage
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $locale,
        public readonly ?string $value,
        public readonly mixed $typed = null,
    ) {}
}
