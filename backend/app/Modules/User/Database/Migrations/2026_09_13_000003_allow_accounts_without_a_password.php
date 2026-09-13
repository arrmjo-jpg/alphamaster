<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let an account exist without a password, and never let an administrator be one.
     *
     * A person who registered through a social provider signs in through it and has no
     * password until they set one (ADR 0050 §12). `NULL` says so plainly; a random
     * placeholder would be a credential nobody knows, making the account look
     * password-capable and hiding that it is not.
     *
     * An administrator is the exception, enforced here rather than remembered: every
     * administrative sign-in is a password plus a second factor (ADR 0012, ADR 0013).
     *
     * ## SQLite rebuilds the table
     *
     * Dropping NOT NULL on SQLite recreates `users`, and the triggers that guard it — the
     * account type constraint and the social promotion rule among them — are not
     * guaranteed to survive a rebuild. So every trigger on the table is read before the
     * change and any that went missing is recreated after it, from its own stored SQL,
     * rather than from a list here that would have to be kept in step.
     *
     * Triggers on other tables that read `users` — the social identity rule does — are a
     * second problem: the rebuild renames a copy into place, SQLite checks every trigger
     * in the schema while it does, and for that moment `users` does not exist. Those are
     * set aside before the rebuild and put back after it, the same way.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $triggers = $driver === 'sqlite' ? $this->setAsideSqliteTriggers() : [];

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });

        match ($driver) {
            'pgsql' => DB::statement(
                'ALTER TABLE users ADD CONSTRAINT chk_users_admin_has_password '.
                "CHECK (account_type <> 'admin' OR password IS NOT NULL)"
            ),
            'sqlite' => $this->finishSqlite($triggers),
            default => throw new RuntimeException(
                "The users password invariant has no implementation for the [{$driver}] driver."
            ),
        };
    }

    /**
     * Refused while any account has no password, because restoring NOT NULL would have to
     * invent one for it.
     */
    public function down(): void
    {
        if (DB::table('users')->whereNull('password')->exists()) {
            throw new RuntimeException(
                'Accounts without a password exist, so the password column cannot be made required again.'
            );
        }

        $driver = Schema::getConnection()->getDriverName();

        match ($driver) {
            'pgsql' => DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_admin_has_password'),
            'sqlite' => array_map(
                static fn (string $event) => DB::statement('DROP TRIGGER IF EXISTS chk_users_admin_has_password_'.strtolower($event)),
                ['INSERT', 'UPDATE']
            ),
            default => null,
        };

        $triggers = $driver === 'sqlite' ? $this->setAsideSqliteTriggers() : [];

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });

        if ($driver === 'sqlite') {
            $this->restoreSqliteTriggers($triggers);
        }
    }

    /**
     * @param  array<string, string>  $triggers
     */
    private function finishSqlite(array $triggers): void
    {
        $this->restoreSqliteTriggers($triggers);

        foreach (['INSERT', 'UPDATE'] as $event) {
            DB::statement(
                'CREATE TRIGGER chk_users_admin_has_password_'.strtolower($event).
                " BEFORE {$event} ON users ".
                "FOR EACH ROW WHEN NEW.account_type = 'admin' AND NEW.password IS NULL ".
                "BEGIN SELECT RAISE(ABORT, 'chk_users_admin_has_password'); END"
            );
        }
    }

    /**
     * Every trigger the rebuild of `users` touches, by name, with the SQL that created it.
     *
     * Those on `users` are left in place for the rebuild to carry or lose. Those on other
     * tables that read `users` are dropped now, because the rebuild cannot finish while
     * they exist.
     *
     * @return array<string, string>
     */
    private function setAsideSqliteTriggers(): array
    {
        $triggers = [];

        foreach (DB::select("SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'trigger'") as $row) {
            $onUsers = (string) $row->tbl_name === 'users';

            if (! $onUsers && preg_match('/\busers\b/i', (string) $row->sql) !== 1) {
                continue;
            }

            $triggers[(string) $row->name] = (string) $row->sql;

            if (! $onUsers) {
                DB::statement('DROP TRIGGER IF EXISTS "'.str_replace('"', '""', (string) $row->name).'"');
            }
        }

        return $triggers;
    }

    /**
     * Recreate each set-aside trigger that no longer exists.
     *
     * @param  array<string, string>  $triggers
     */
    private function restoreSqliteTriggers(array $triggers): void
    {
        $present = array_map(
            static fn (object $row): string => (string) $row->name,
            DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger'")
        );

        foreach ($triggers as $name => $sql) {
            if (! in_array($name, $present, true)) {
                DB::statement($sql);
            }
        }
    }
};
