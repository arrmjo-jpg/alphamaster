<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mark which settings carry a value per locale (ADR 0018, extension 2026-09-04).
 *
 * The flag lives on the row rather than only in the registry because the resolver
 * and the cache both need it while reading, and a read that had to consult the
 * registry to know how to read would make the registry a runtime dependency of
 * every settings lookup.
 *
 * The invariant travels with it. A secret is never localized: a credential has no
 * language, and a per-locale copy multiplies the thing that has to be protected.
 * It is enforced at the engine level for the same reason as the existing two —
 * it must hold when model events are disabled and for a raw query-builder write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->boolean('is_localized')->default(false)->after('is_public');
        });

        $this->applyInvariant();
    }

    public function down(): void
    {
        $this->dropInvariant();

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn('is_localized');
        });
    }

    private function applyInvariant(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        match ($driver) {
            'pgsql' => DB::statement(
                'ALTER TABLE settings ADD CONSTRAINT chk_settings_secret_never_localized '.
                'CHECK (NOT (is_secret = TRUE AND is_localized = TRUE))'
            ),
            'sqlite' => $this->applySqliteTriggers(),
            default => throw new RuntimeException(
                "The settings localization invariant has no implementation for the [{$driver}] driver."
            ),
        };
    }

    private function dropInvariant(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE settings DROP CONSTRAINT IF EXISTS chk_settings_secret_never_localized');

            return;
        }

        foreach (['insert', 'update'] as $event) {
            DB::statement("DROP TRIGGER IF EXISTS chk_settings_secret_never_localized_{$event}");
        }
    }

    /**
     * SQLite has no ALTER TABLE ADD CONSTRAINT, so the invariant is a pair of
     * BEFORE triggers that RAISE(ABORT) — the same shape the original migration uses,
     * surfacing as a QueryException exactly like a violated CHECK.
     */
    private function applySqliteTriggers(): void
    {
        foreach (['INSERT', 'UPDATE'] as $event) {
            $trigger = 'chk_settings_secret_never_localized_'.strtolower($event);

            DB::statement(
                "CREATE TRIGGER {$trigger} BEFORE {$event} ON settings ".
                'FOR EACH ROW WHEN NEW.is_secret = 1 AND NEW.is_localized = 1 '.
                "BEGIN SELECT RAISE(ABORT, 'chk_settings_secret_never_localized'); END"
            );
        }
    }
};
