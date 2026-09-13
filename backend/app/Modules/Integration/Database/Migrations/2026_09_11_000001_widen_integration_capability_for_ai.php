<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a provider row name the AI capability, and let a usage row carry units.
     *
     * The capability column is constrained at the database level — a CHECK on
     * PostgreSQL, BEFORE triggers on SQLite — to the list that existed when the last
     * migration touched it. Adding a case to the enum does not widen that list, so
     * without this the first AI provider is refused by the database and the failure
     * reads as a constraint violation with no obvious cause.
     *
     * The permitted values are hardcoded rather than read from the enum, exactly as
     * the two migrations before this one do and for the same reason: a migration is a
     * historical snapshot, and one deriving its constraint from today's enum would
     * rewrite the past every time the enum changed.
     *
     * `units` arrives here because it arrives with AI. SMS and CAPTCHA charge per
     * attempt and the count is always one, so the column is null for them; a
     * generation charges by the token, and the number the vendor reported is a fact
     * worth keeping. It is not turned into money — prices change, and a stored cost is
     * wrong retroactively in a table nobody re-reads (ADR 0044 §8).
     */
    private const CAPABILITIES_BEFORE = ['sms', 'captcha'];

    /**
     * @var array<int, string>
     */
    private const CAPABILITIES_AFTER = ['sms', 'captcha', 'ai'];

    public function up(): void
    {
        $this->rewriteCapabilityConstraint(self::CAPABILITIES_AFTER);

        Schema::table('integration_usage_logs', function (Blueprint $table): void {
            // Nullable and unsigned: a capability that does not charge by unit has
            // nothing to say here, and no vendor reports a negative one.
            $table->unsignedInteger('units')->nullable()->after('duration_ms');
        });
    }

    /**
     * Narrow the constraint back.
     *
     * Any AI row is removed first. Rolling back with one present would leave the table
     * holding a value its own constraint forbids, which PostgreSQL refuses outright
     * and SQLite accepts silently — a worse outcome, because the row survives and
     * nothing says so.
     */
    public function down(): void
    {
        Schema::table('integration_usage_logs', function (Blueprint $table): void {
            $table->dropColumn('units');
        });

        DB::table('integration_usage_logs')->where('capability', 'ai')->delete();
        DB::table('integration_providers')->where('capability', 'ai')->delete();

        $this->rewriteCapabilityConstraint(self::CAPABILITIES_BEFORE);
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function rewriteCapabilityConstraint(array $capabilities): void
    {
        $quoted = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            $capabilities
        ));

        $driver = Schema::getConnection()->getDriverName();

        match ($driver) {
            'pgsql' => $this->rewriteCheckConstraints($quoted),
            'sqlite' => $this->rewriteSqliteTriggers($quoted),
            default => throw new RuntimeException(
                "The integration capability constraint has no implementation for the [{$driver}] driver."
            ),
        };
    }

    /**
     * Dropped and re-added rather than altered: PostgreSQL has no ALTER CONSTRAINT for
     * a CHECK expression, so replacing it is the only way to change what it permits.
     */
    private function rewriteCheckConstraints(string $capabilities): void
    {
        foreach (['integration_providers', 'integration_usage_logs'] as $table) {
            $name = $table === 'integration_providers'
                ? 'chk_integration_providers_capability'
                : 'chk_integration_usage_capability';

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$name} ".
                "CHECK (capability IN ({$capabilities}))"
            );
        }
    }

    /**
     * SQLite expresses the same invariant as a pair of triggers per table, so the pair
     * is dropped and rebuilt with the new list. The names and shape match the creating
     * migration exactly; a divergence here would mean the two engines stopped
     * enforcing the same rule, which is the one thing running both is meant to catch.
     */
    private function rewriteSqliteTriggers(string $capabilities): void
    {
        $rules = [
            'chk_integration_providers_capability' => ['integration_providers', "NEW.capability NOT IN ({$capabilities})"],
            'chk_integration_usage_capability' => ['integration_usage_logs', "NEW.capability NOT IN ({$capabilities})"],
        ];

        foreach ($rules as $name => [$table, $violation]) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $trigger = $name.'_'.strtolower($event);

                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
                DB::statement(
                    "CREATE TRIGGER {$trigger} BEFORE {$event} ON {$table} ".
                    "FOR EACH ROW WHEN {$violation} ".
                    "BEGIN SELECT RAISE(ABORT, '{$name}'); END"
                );
            }
        }
    }
};
