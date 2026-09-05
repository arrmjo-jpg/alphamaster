<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring `languages` onto the platform's timestamp convention (ADR 0029 item 7).
 *
 * The table was created with a naive `timestamp` while every other table this
 * module and its neighbours create stores `timestamptz`. See the matching
 * migration in the Authorization module for the same conversion on the Spatie
 * tables; each module converts the tables it owns.
 *
 * SQLite has no timezone-aware timestamp type, so there is nothing to convert
 * there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['created_at', 'updated_at'] as $column) {
            // Reinterpreted as UTC, which is what Eloquent wrote, so no row shifts.
            DB::statement(
                "ALTER TABLE languages ALTER COLUMN {$column} TYPE timestamptz USING {$column} AT TIME ZONE 'UTC'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['created_at', 'updated_at'] as $column) {
            DB::statement(
                "ALTER TABLE languages ALTER COLUMN {$column} TYPE timestamp USING {$column} AT TIME ZONE 'UTC'"
            );
        }
    }
};
