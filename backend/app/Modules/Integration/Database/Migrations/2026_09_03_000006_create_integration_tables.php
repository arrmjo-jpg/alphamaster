<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hardcoded rather than derived from the enums: a migration is a historical
     * snapshot and must not change retroactively when they evolve.
     *
     * @var array<int, string>
     */
    private const CAPABILITIES = ['sms'];

    /**
     * @var array<int, string>
     */
    private const STATUSES = ['success', 'failure'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('integration_providers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('capability', 30);
            $table->string('driver', 50);
            $table->string('label', 100);
            // Vendor credentials, encrypted at rest and never rendered by any API.
            $table->text('credentials')->nullable();
            // Non-secret driver settings, safe to display.
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            // Failover order among the non-default providers for a capability.
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestampsTz();

            $table->unique(['capability', 'driver'], 'idx_integration_providers_capability_driver');
            $table->index(['capability', 'is_active', 'priority'], 'idx_integration_providers_selection');
        });

        Schema::create('integration_usage_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Kept on delete: usage history outlives the provider row it refers to.
            $table->foreignUlid('integration_provider_id')->nullable()
                ->constrained('integration_providers')->nullOnDelete();
            // Denormalised so a log line stays readable after a provider is removed.
            $table->string('capability', 30);
            $table->string('driver', 50);
            $table->string('status', 20);
            $table->string('reference', 191)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampsTz();

            $table->index(['capability', 'status', 'created_at'], 'idx_integration_usage_capability_status');
        });

        $this->applyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_usage_logs');
        Schema::dropIfExists('integration_providers');
    }

    /**
     * Constrain the enum-backed columns at the database level, and enforce that a
     * capability has at most one default provider.
     *
     * PostgreSQL is the sole production engine (ADR 0003); SQLite is supported only
     * so the local harness exercises the same behaviour (ADR 0027).
     */
    private function applyConstraints(): void
    {
        $capabilities = $this->quote(self::CAPABILITIES);
        $statuses = $this->quote(self::STATUSES);
        $driver = Schema::getConnection()->getDriverName();

        // A partial unique index expresses "at most one default per capability" on
        // both engines, the same approach the languages table uses for is_default.
        DB::statement(
            'CREATE UNIQUE INDEX idx_integration_providers_single_default '.
            'ON integration_providers (capability) WHERE is_default = TRUE'
        );

        match ($driver) {
            'pgsql' => $this->applyCheckConstraints($capabilities, $statuses),
            'sqlite' => $this->applySqliteTriggers($capabilities, $statuses),
            default => throw new RuntimeException(
                "The integration invariants have no implementation for the [{$driver}] driver."
            ),
        };
    }

    private function applyCheckConstraints(string $capabilities, string $statuses): void
    {
        DB::statement(
            'ALTER TABLE integration_providers ADD CONSTRAINT chk_integration_providers_capability '.
            "CHECK (capability IN ({$capabilities}))"
        );
        DB::statement(
            'ALTER TABLE integration_usage_logs ADD CONSTRAINT chk_integration_usage_capability '.
            "CHECK (capability IN ({$capabilities}))"
        );
        DB::statement(
            'ALTER TABLE integration_usage_logs ADD CONSTRAINT chk_integration_usage_status '.
            "CHECK (status IN ({$statuses}))"
        );
    }

    private function applySqliteTriggers(string $capabilities, string $statuses): void
    {
        $rules = [
            'chk_integration_providers_capability' => ['integration_providers', "NEW.capability NOT IN ({$capabilities})"],
            'chk_integration_usage_capability' => ['integration_usage_logs', "NEW.capability NOT IN ({$capabilities})"],
            'chk_integration_usage_status' => ['integration_usage_logs', "NEW.status NOT IN ({$statuses})"],
        ];

        foreach ($rules as $name => [$table, $violation]) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement(
                    'CREATE TRIGGER '.$name.'_'.strtolower($event).
                    " BEFORE {$event} ON {$table} ".
                    "FOR EACH ROW WHEN {$violation} ".
                    "BEGIN SELECT RAISE(ABORT, '{$name}'); END"
                );
            }
        }
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quote(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'".$v."'", $values));
    }
};
