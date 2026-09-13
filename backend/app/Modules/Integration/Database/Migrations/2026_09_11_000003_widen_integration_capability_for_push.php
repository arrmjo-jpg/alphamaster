<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a provider row name the push capability.
     *
     * The third migration to rewrite this constraint, and it repeats the shape of the
     * two before it for the same reason each of them gives: the permitted values are
     * hardcoded rather than read from the enum, because a migration is a historical
     * snapshot and one deriving its constraint from today's enum would rewrite the
     * past every time the enum changed.
     */
    private const CAPABILITIES_BEFORE = ['sms', 'captcha', 'ai'];

    /**
     * @var array<int, string>
     */
    private const CAPABILITIES_AFTER = ['sms', 'captcha', 'ai', 'push'];

    public function up(): void
    {
        $this->rewriteCapabilityConstraint(self::CAPABILITIES_AFTER);
    }

    /**
     * Any push row is removed first. Rolling back with one present would leave the
     * table holding a value its own constraint forbids, which PostgreSQL refuses
     * outright and SQLite accepts silently — a worse outcome, because the row survives
     * and nothing says so.
     */
    public function down(): void
    {
        DB::table('integration_usage_logs')->where('capability', 'push')->delete();
        DB::table('integration_providers')->where('capability', 'push')->delete();

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
