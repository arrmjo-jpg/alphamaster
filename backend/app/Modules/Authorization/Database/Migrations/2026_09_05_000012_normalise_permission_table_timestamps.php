<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring the Spatie tables onto the platform's timestamp convention (ADR 0029 item 7).
 *
 * Every table this platform creates stores `timestamptz`, so that a deployment
 * whose server zone changes does not silently reinterpret rows already written.
 * `permissions` and `roles` came from Spatie's published migration and were left
 * on a naive `timestamp`.
 *
 * SQLite has no timezone-aware timestamp type — `timestamps()` and
 * `timestampsTz()` both produce `datetime` — so there is nothing to convert
 * there and the tables are already consistent by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['permissions', 'roles'] as $table) {
            foreach (['created_at', 'updated_at'] as $column) {
                // Existing values are naive timestamps that Eloquent wrote in UTC,
                // so they are reinterpreted as UTC rather than in the server's own
                // zone. No row shifts.
                DB::statement(
                    "ALTER TABLE {$table} ALTER COLUMN {$column} TYPE timestamptz USING {$column} AT TIME ZONE 'UTC'"
                );
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['permissions', 'roles'] as $table) {
            foreach (['created_at', 'updated_at'] as $column) {
                DB::statement(
                    "ALTER TABLE {$table} ALTER COLUMN {$column} TYPE timestamp USING {$column} AT TIME ZONE 'UTC'"
                );
            }
        }
    }
};
