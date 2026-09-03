<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hardcoded rather than derived from MfaType: a migration is a historical
     * snapshot and must not change retroactively when the enum evolves.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TYPES = ['totp', 'sms_otp'];

    /**
     * @var array<int, string>
     */
    private const PREVIOUS_TYPES = ['totp'];

    /**
     * Make room for a delivery-based method alongside the shared-secret one.
     */
    public function up(): void
    {
        Schema::table('mfa_methods', function (Blueprint $table): void {
            // Where a delivered code is sent, encrypted at rest. A phone number is
            // personal data and belongs under the same protection as a secret.
            $table->text('destination')->nullable()->after('secret');
            // The pending code, hashed. Only a hash is stored, so a database read
            // cannot yield a usable code, exactly as with recovery codes.
            $table->string('otp_hash')->nullable()->after('destination');
            $table->timestampTz('otp_expires_at')->nullable()->after('otp_hash');
            $table->timestampTz('otp_sent_at')->nullable()->after('otp_expires_at');
        });

        // Order matters: on SQLite the column change rebuilds the table and drops its
        // triggers, so the type invariant is reinstated afterwards.
        $this->makeSecretNullable();
        $this->replaceTypeConstraint(self::ALLOWED_TYPES);
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        DB::table('mfa_methods')->where('type', 'sms_otp')->delete();

        Schema::table('mfa_methods', function (Blueprint $table): void {
            $table->dropColumn(['destination', 'otp_hash', 'otp_expires_at', 'otp_sent_at']);
        });

        $this->replaceTypeConstraint(self::PREVIOUS_TYPES);
    }

    /**
     * Drop the NOT NULL on secret.
     *
     * TOTP has a shared secret and a delivery method does not, so the column can no
     * longer be required. Both engines enforce NOT NULL, so this is a real change on
     * both rather than something SQLite can be assumed to ignore. Laravel 11 onwards
     * performs it natively, without doctrine/dbal.
     */
    private function makeSecretNullable(): void
    {
        Schema::table('mfa_methods', function (Blueprint $table): void {
            $table->text('secret')->nullable()->change();
        });
    }

    /**
     * Replace the type invariant with one covering the given values.
     *
     * @param  array<int, string>  $types
     */
    private function replaceTypeConstraint(array $types): void
    {
        $quoted = implode(', ', array_map(static fn (string $t): string => "'".$t."'", $types));
        $driver = Schema::getConnection()->getDriverName();

        match ($driver) {
            'pgsql' => $this->replaceCheckConstraint($quoted),
            'sqlite' => $this->replaceSqliteTriggers($quoted),
            default => throw new RuntimeException(
                "The mfa_methods type invariant has no implementation for the [{$driver}] driver."
            ),
        };
    }

    private function replaceCheckConstraint(string $quoted): void
    {
        DB::statement('ALTER TABLE mfa_methods DROP CONSTRAINT IF EXISTS chk_mfa_methods_type_allowed');
        DB::statement(
            'ALTER TABLE mfa_methods ADD CONSTRAINT chk_mfa_methods_type_allowed '.
            "CHECK (type IN ({$quoted}))"
        );
    }

    private function replaceSqliteTriggers(string $quoted): void
    {
        foreach (['INSERT', 'UPDATE'] as $event) {
            $name = 'chk_mfa_methods_type_allowed_'.strtolower($event);

            DB::statement("DROP TRIGGER IF EXISTS {$name}");
            DB::statement(
                "CREATE TRIGGER {$name} BEFORE {$event} ON mfa_methods ".
                "FOR EACH ROW WHEN NEW.type NOT IN ({$quoted}) ".
                "BEGIN SELECT RAISE(ABORT, 'chk_mfa_methods_type_allowed'); END"
            );
        }
    }
};
