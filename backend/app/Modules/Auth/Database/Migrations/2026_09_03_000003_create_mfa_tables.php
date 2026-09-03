<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allowed MFA method types.
     *
     * Hardcoded rather than derived from MfaType: a migration is a historical
     * snapshot and must not change retroactively when the enum evolves.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TYPES = ['totp'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mfa_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20);
            // Encrypted at rest; never stored or logged in plaintext.
            $table->text('secret');
            // Null until the enrolment is proven with a valid code.
            $table->timestampTz('confirmed_at')->nullable();
            // Highest TOTP time-slice already accepted. Replay protection compares
            // against this, so a code cannot be presented twice within its window.
            $table->unsignedBigInteger('last_used_slice')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'type'], 'idx_mfa_methods_user_type_unique');
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            // Only a hash is stored. The plaintext is shown once, at generation.
            $table->string('code_hash');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'used_at'], 'idx_mfa_recovery_user_used');
        });

        $this->applyTypeConstraint();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('mfa_methods');
    }

    /**
     * Restrict `type` to the known MFA methods at the database level, matching the
     * approach the settings table uses. PostgreSQL is the sole production engine
     * (ADR 0003); SQLite is supported only so the local harness exercises the same
     * behaviour (ADR 0027).
     */
    private function applyTypeConstraint(): void
    {
        $types = implode(', ', array_map(
            static fn (string $t): string => "'".$t."'",
            self::ALLOWED_TYPES
        ));

        $driver = Schema::getConnection()->getDriverName();

        match ($driver) {
            'pgsql' => DB::statement(
                'ALTER TABLE mfa_methods ADD CONSTRAINT chk_mfa_methods_type_allowed '.
                "CHECK (type IN ({$types}))"
            ),
            'sqlite' => $this->applySqliteTriggers($types),
            default => throw new RuntimeException(
                "The mfa_methods type invariant has no implementation for the [{$driver}] driver."
            ),
        };
    }

    /**
     * SQLite has no ALTER TABLE ADD CONSTRAINT, so the same invariant is enforced
     * with triggers that RAISE(ABORT), surfacing as a QueryException just like a
     * violated CHECK.
     */
    private function applySqliteTriggers(string $types): void
    {
        foreach (['INSERT', 'UPDATE'] as $event) {
            DB::statement(
                'CREATE TRIGGER chk_mfa_methods_type_allowed_'.strtolower($event).
                " BEFORE {$event} ON mfa_methods ".
                "FOR EACH ROW WHEN NEW.type NOT IN ({$types}) ".
                "BEGIN SELECT RAISE(ABORT, 'chk_mfa_methods_type_allowed'); END"
            );
        }
    }
};
