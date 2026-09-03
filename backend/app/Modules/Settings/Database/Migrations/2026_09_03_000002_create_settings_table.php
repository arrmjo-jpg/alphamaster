<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allowed values for the `type` column.
     *
     * Intentionally hardcoded rather than derived from SettingType: a migration is a
     * historical snapshot and must not change retroactively when the enum evolves.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TYPES = ['string', 'integer', 'float', 'boolean', 'json'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('group', 50)->index();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->boolean('is_secret')->default(false);
            $table->boolean('is_public')->default(false);
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->unique(['group', 'key'], 'idx_settings_group_key_unique');
            $table->index(['group', 'is_public'], 'idx_settings_group_public');
        });

        $this->applyInvariants();
    }

    /**
     * Reverse the migrations.
     *
     * Dropping the table also drops its CHECK constraints (PostgreSQL/MySQL) and
     * its triggers (SQLite), so no explicit teardown is required.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }

    /**
     * Enforce both data invariants at the database engine level:
     *   1. A secret setting is NEVER public.
     *   2. `type` is restricted to the known SettingType values.
     *
     * These must hold independently of application-level model events, which can be
     * disabled (e.g. WithoutModelEvents) or bypassed by raw query-builder writes.
     */
    private function applyInvariants(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // PostgreSQL is the sole production engine (ADR 0003); SQLite is supported here
        // only because the local test harness runs on it. No other engine is targeted:
        // ADR 0003 deliberately abandons generic multi-database portability.
        match ($driver) {
            'pgsql' => $this->applyCheckConstraints(),
            'sqlite' => $this->applySqliteTriggers(),
            default => throw new RuntimeException(
                "The settings table invariants have no implementation for the [{$driver}] driver."
            ),
        };
    }

    /**
     * PostgreSQL: native CHECK constraints.
     */
    private function applyCheckConstraints(): void
    {
        DB::statement(
            'ALTER TABLE settings ADD CONSTRAINT chk_settings_secret_never_public '.
            'CHECK (NOT (is_secret = TRUE AND is_public = TRUE))'
        );

        DB::statement(
            'ALTER TABLE settings ADD CONSTRAINT chk_settings_type_allowed '.
            'CHECK (type IN ('.$this->quotedTypeList().'))'
        );
    }

    /**
     * SQLite: no ALTER TABLE ADD CONSTRAINT support, so the same two invariants are
     * enforced with BEFORE INSERT / BEFORE UPDATE triggers that RAISE(ABORT).
     * A raised ABORT surfaces as a QueryException, exactly like a violated CHECK.
     */
    private function applySqliteTriggers(): void
    {
        $types = $this->quotedTypeList();

        $conditions = [
            'chk_settings_secret_never_public' => 'NEW.is_secret = 1 AND NEW.is_public = 1',
            'chk_settings_type_allowed' => "NEW.type NOT IN ({$types})",
        ];

        foreach ($conditions as $name => $violation) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $trigger = $name.'_'.strtolower($event);

                DB::statement(
                    "CREATE TRIGGER {$trigger} BEFORE {$event} ON settings ".
                    "FOR EACH ROW WHEN {$violation} ".
                    "BEGIN SELECT RAISE(ABORT, '{$name}'); END"
                );
            }
        }
    }

    /**
     * Render the allowed type list as a quoted SQL value list.
     */
    private function quotedTypeList(): string
    {
        return implode(', ', array_map(
            static fn (string $type): string => "'".$type."'",
            self::ALLOWED_TYPES
        ));
    }
};
