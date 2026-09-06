<?php

declare(strict_types=1);

use App\Modules\Settings\Enums\SettingType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admit `url`, `email` and `media` to the settings type constraint.
 *
 * The constraint is engine-level for the reason ADR 0018 gives: it must hold when
 * model events are disabled and when rows are written through the query builder.
 * That also means widening the set of types is a schema change, which is exactly
 * the separation the revised ADR 0018 draws — schema is migrations, definitions
 * are the registry.
 *
 * `media` is the one ADR 0018 named as a prerequisite for branding: a setting whose
 * value is a media id must be validatable as one rather than as an opaque string.
 */
return new class extends Migration
{
    /**
     * The constraint is rebuilt from the enum rather than from a copied list, so a
     * type added to `SettingType` without a migration fails the schema assertion
     * instead of being silently rejected by the database at runtime.
     *
     * @return array<int, string>
     */
    private function allowedTypes(): array
    {
        return SettingType::values();
    }

    /**
     * The type set before this migration.
     *
     * @return array<int, string>
     */
    private function previousTypes(): array
    {
        return ['string', 'integer', 'float', 'boolean', 'json'];
    }

    public function up(): void
    {
        $this->rebuildTypeConstraint($this->allowedTypes());
    }

    /**
     * Reversing narrows the set again. A row already stored with one of the new types
     * would violate the restored constraint, so those rows are refused rather than
     * rewritten: silently changing a setting's type would corrupt its value.
     */
    public function down(): void
    {
        $offending = DB::table('settings')
            ->whereIn('type', array_diff($this->allowedTypes(), $this->previousTypes()))
            ->count();

        if ($offending > 0) {
            throw new RuntimeException(
                "Refusing to narrow the settings type constraint: {$offending} row(s) use a type it would forbid."
            );
        }

        $this->rebuildTypeConstraint($this->previousTypes());
    }

    /**
     * @param  array<int, string>  $types
     */
    private function rebuildTypeConstraint(array $types): void
    {
        $driver = Schema::getConnection()->getDriverName();

        match ($driver) {
            'pgsql' => $this->rebuildCheckConstraint($types),
            'sqlite' => $this->rebuildSqliteTriggers($types),
            default => throw new RuntimeException(
                "The settings type constraint has no implementation for the [{$driver}] driver."
            ),
        };
    }

    /**
     * @param  array<int, string>  $types
     */
    private function rebuildCheckConstraint(array $types): void
    {
        DB::statement('ALTER TABLE settings DROP CONSTRAINT IF EXISTS chk_settings_type_allowed');

        DB::statement(
            'ALTER TABLE settings ADD CONSTRAINT chk_settings_type_allowed '.
            'CHECK (type IN ('.$this->quoted($types).'))'
        );
    }

    /**
     * SQLite cannot alter a constraint, so the pair of triggers is dropped and
     * recreated with the same names the original migration used.
     *
     * @param  array<int, string>  $types
     */
    private function rebuildSqliteTriggers(array $types): void
    {
        $list = $this->quoted($types);

        foreach (['INSERT', 'UPDATE'] as $event) {
            $trigger = 'chk_settings_type_allowed_'.strtolower($event);

            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");

            DB::statement(
                "CREATE TRIGGER {$trigger} BEFORE {$event} ON settings ".
                "FOR EACH ROW WHEN NEW.type NOT IN ({$list}) ".
                "BEGIN SELECT RAISE(ABORT, 'chk_settings_type_allowed'); END"
            );
        }
    }

    /**
     * @param  array<int, string>  $types
     */
    private function quoted(array $types): string
    {
        return implode(', ', array_map(static fn (string $t): string => "'".$t."'", $types));
    }
};
