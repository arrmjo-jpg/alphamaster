<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a provider row name the social login capability (ADR 0050).
     *
     * The fourth migration to rewrite this constraint, in the same shape as the three
     * before it: the permitted values are written out rather than read from the enum,
     * because a migration is a historical snapshot.
     */
    private const CAPABILITIES_BEFORE = ['sms', 'captcha', 'ai', 'push'];

    /**
     * @var array<int, string>
     */
    private const CAPABILITIES_AFTER = ['sms', 'captcha', 'ai', 'push', 'social_login'];

    public function up(): void
    {
        $this->rewriteCapabilityConstraint(self::CAPABILITIES_AFTER);
    }

    /**
     * Social login rows are removed first. Rolling back with one present would leave the
     * table holding a value its own constraint forbids, which PostgreSQL refuses outright
     * and SQLite accepts silently.
     */
    public function down(): void
    {
        DB::table('integration_usage_logs')->where('capability', 'social_login')->delete();
        DB::table('integration_providers')->where('capability', 'social_login')->delete();

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
