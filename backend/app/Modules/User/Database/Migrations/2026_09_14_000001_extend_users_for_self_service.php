<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a user account exist without an email address, and give accounts a profile and
     * a location (ADR 0051).
     *
     * A person who registers with a phone number has no address until they add one.
     * `NULL` says so; an invented placeholder would be an address nobody reads and a
     * uniqueness collision waiting to happen. An administrator is the exception, enforced
     * here rather than remembered: administrative sign-in is stopped until the address is
     * verified (ADR 0012), so an administrator without one could never sign in.
     *
     * ## SQLite rebuilds the table
     *
     * Dropping NOT NULL on SQLite recreates `users`, exactly as ADR 0050's password
     * migration did. Triggers on `users` are carried or restored from their own SQL, and
     * triggers elsewhere that read `users` are set aside for the rebuild and put back.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $triggers = $driver === 'sqlite' ? $this->setAsideSqliteTriggers() : [];

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();

            $table->string('bio', 500)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestampTz('location_updated_at')->nullable();
        });

        match ($driver) {
            'pgsql' => $this->addPostgresConstraints(),
            'sqlite' => $this->finishSqlite($triggers),
            default => throw new RuntimeException(
                "The users profile invariants have no implementation for the [{$driver}] driver."
            ),
        };
    }

    /**
     * Refused while any account has no address, because restoring NOT NULL would have to
     * invent one for it.
     */
    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException(
                'Accounts without an email address exist, so the email column cannot be made required again.'
            );
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            foreach (['chk_users_admin_has_email', 'chk_users_country_code_shape', 'chk_users_coordinates_pair', 'chk_users_coordinates_range'] as $constraint) {
                DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }

        if ($driver === 'sqlite') {
            foreach (['insert', 'update'] as $event) {
                DB::statement("DROP TRIGGER IF EXISTS chk_users_admin_has_email_{$event}");
            }
        }

        $triggers = $driver === 'sqlite' ? $this->setAsideSqliteTriggers() : [];

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['bio', 'country_code', 'region', 'city', 'latitude', 'longitude', 'location_updated_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
        });

        if ($driver === 'sqlite') {
            $this->restoreSqliteTriggers($triggers);
        }
    }

    private function addPostgresConstraints(): void
    {
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT chk_users_admin_has_email '.
            "CHECK (account_type <> 'admin' OR email IS NOT NULL)"
        );

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT chk_users_country_code_shape '.
            "CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$')"
        );

        // Both or neither: a latitude without a longitude is not a place.
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT chk_users_coordinates_pair '.
            'CHECK ((latitude IS NULL) = (longitude IS NULL))'
        );

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT chk_users_coordinates_range '.
            'CHECK (latitude IS NULL OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))'
        );
    }

    /**
     * @param  array<string, string>  $triggers
     */
    private function finishSqlite(array $triggers): void
    {
        $this->restoreSqliteTriggers($triggers);

        foreach (['INSERT', 'UPDATE'] as $event) {
            DB::statement(
                'CREATE TRIGGER chk_users_admin_has_email_'.strtolower($event).
                " BEFORE {$event} ON users ".
                "FOR EACH ROW WHEN NEW.account_type = 'admin' AND NEW.email IS NULL ".
                "BEGIN SELECT RAISE(ABORT, 'chk_users_admin_has_email'); END"
            );
        }
    }

    /**
     * Every trigger the rebuild of `users` touches, by name, with the SQL that created it.
     *
     * Those on `users` are left for the rebuild to carry or lose. Those on other tables
     * that read `users` are dropped now, because the rebuild cannot finish while they
     * exist.
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
