<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allowed account types.
     *
     * Hardcoded rather than derived from AccountType: a migration is a historical
     * snapshot and must not change retroactively when the enum evolves.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TYPES = ['admin', 'user'];

    /**
     * Replace the is_admin boolean with an explicit account type discriminator.
     *
     * Written as a forward migration rather than an edit to the base users migration,
     * because that baseline is already merged: an existing database must be able to
     * reach this state with `migrate`, not only with `migrate:fresh`.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_type', 20)->default('user')->after('email_verified_at')->index();
        });

        // Carry existing administrators across before the old column goes; everyone
        // else is already 'user' by the column default.
        DB::table('users')->where('is_admin', true)->update(['account_type' => 'admin']);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_admin');
        });

        $this->applyTypeConstraint();
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        $this->dropTypeConstraint();

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
        });

        DB::table('users')->where('account_type', 'admin')->update(['is_admin' => true]);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('account_type');
        });
    }

    /**
     * Constrain account_type at the database level, the way settings.type and
     * mfa_methods.type already are: an invariant this important must survive raw
     * writes and disabled model events.
     */
    private function applyTypeConstraint(): void
    {
        $types = implode(', ', array_map(
            static fn (string $t): string => "'".$t."'",
            self::ALLOWED_TYPES
        ));

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => DB::statement(
                'ALTER TABLE users ADD CONSTRAINT chk_users_account_type_allowed '.
                "CHECK (account_type IN ({$types}))"
            ),
            'sqlite' => $this->applySqliteTriggers($types),
            default => throw new RuntimeException(
                'The users account_type invariant has no implementation for this driver.'
            ),
        };
    }

    /**
     * SQLite has no ALTER TABLE ADD CONSTRAINT, so triggers enforce the same rule.
     */
    private function applySqliteTriggers(string $types): void
    {
        foreach (['INSERT', 'UPDATE'] as $event) {
            DB::statement(
                'CREATE TRIGGER chk_users_account_type_allowed_'.strtolower($event).
                " BEFORE {$event} ON users ".
                "FOR EACH ROW WHEN NEW.account_type NOT IN ({$types}) ".
                "BEGIN SELECT RAISE(ABORT, 'chk_users_account_type_allowed'); END"
            );
        }
    }

    /**
     * Remove the constraint so the column can be dropped on rollback.
     */
    private function dropTypeConstraint(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_account_type_allowed'),
            'sqlite' => array_map(
                static fn (string $event) => DB::statement(
                    'DROP TRIGGER IF EXISTS chk_users_account_type_allowed_'.strtolower($event)
                ),
                ['INSERT', 'UPDATE']
            ),
            default => null,
        };
    }
};
