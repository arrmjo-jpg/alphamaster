<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The identities a social provider can sign in to an account with (ADR 0050).
     *
     * Owned by the User module rather than Auth: `AccountTypeManager` has to read it to
     * refuse a promotion, and User may not depend on Auth (ADR 0002).
     *
     * A row is never deleted when an identity is unlinked. `unlinked_at` is set instead,
     * and the row keeps the provider's subject reserved to the account it belonged to —
     * which is why uniqueness on the subject spans every row, linked or not, while the
     * one-identity-per-provider rule counts only linked rows.
     *
     * Who a row belongs to and which identity it is never change once it exists: an
     * identity never moves between accounts (ADR 0050 §5). Uniqueness alone does not stop
     * that — it prevents a second row, not an existing row being re-pointed — so the
     * owner and the provider identity are immutable at the database level.
     *
     * There is no column for a provider's access token, refresh token or ID token, and
     * none for whether the provider vouched for the address. Neither is kept: tokens are
     * used inside the callback and discarded, and provider verification is not local
     * verification (ADR 0050 §4).
     */
    public function up(): void
    {
        Schema::create('social_identities', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            // The driver key, not the provider row's id, so identities survive a
            // provider row being recreated.
            $table->string('provider', 50);

            // The provider's immutable subject. Never the email: an address is mutable
            // and is reassigned, and a recycled one would sign in the wrong person.
            $table->string('provider_subject', 255);

            // A snapshot for display only. Nothing looks an identity up by it.
            $table->string('email', 255)->nullable();

            $table->timestampTz('linked_at');
            $table->timestampTz('last_used_at')->nullable();

            // Null means linked. Unlinking sets it, and nothing deletes the row.
            $table->timestampTz('unlinked_at')->nullable();

            $table->timestampsTz();

            // Across every row, linked or not: a subject belongs to one account for good.
            $table->unique(['provider', 'provider_subject'], 'uniq_social_identity_subject');

            // The foreign key's own index, for the cascade and for reads by account.
            $table->index(['user_id']);
        });

        // One linked identity per provider per account. Partial, so an unlinked row from
        // the same provider stays as history without blocking a new link. The same shape
        // as the single-default indexes on languages and integration providers, which
        // both engines support.
        //
        // It also serves the promotion check, which asks for an account's linked rows:
        // the index leads with user_id under the same predicate, so no separate partial
        // index on user_id alone is needed.
        DB::statement(
            'CREATE UNIQUE INDEX uniq_social_identity_linked_provider '.
            'ON social_identities (user_id, provider) WHERE unlinked_at IS NULL'
        );

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => $this->applyPostgresInvariants(),
            'sqlite' => $this->applySqliteInvariants(),
            default => throw new RuntimeException(
                'The social identity invariants have no implementation for this driver.'
            ),
        };
    }

    public function down(): void
    {
        // The users trigger names social_identities, so it goes first: dropping the table
        // under it would leave a trigger that fails every account-type change.
        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => DB::statement('DROP TRIGGER IF EXISTS chk_users_admin_without_linked_social_identity ON users'),
            'sqlite' => DB::statement('DROP TRIGGER IF EXISTS chk_users_admin_without_linked_social_identity'),
            default => null,
        };

        // Dropping the table drops the triggers declared on it, on both engines.
        Schema::dropIfExists('social_identities');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS chk_users_admin_without_linked_social_identity()');
            DB::statement('DROP FUNCTION IF EXISTS chk_social_identity_not_admin()');
            DB::statement('DROP FUNCTION IF EXISTS chk_social_identity_immutable()');
        }
    }

    /**
     * PostgreSQL: trigger functions, because two of the rules read a second table and a
     * CHECK constraint cannot, and the third compares a row with its previous version,
     * which a CHECK constraint cannot see either.
     *
     * `CREATE OR REPLACE` because `migrate:fresh` drops tables and not functions; a plain
     * `CREATE` would fail on the second run.
     *
     * The identity trigger reads the account `FOR SHARE`. Without it the two cross-table
     * rules race under READ COMMITTED: a link and a promotion in concurrent transactions
     * each check a state the other has not committed, both pass, and both commit. The
     * foreign key does not prevent it, because its `FOR KEY SHARE` lock does not conflict
     * with an update to a non-key column. `FOR SHARE` does conflict with that update, so
     * the two serialise, and whichever runs second re-reads the committed state and
     * refuses.
     */
    private function applyPostgresInvariants(): void
    {
        // Ownership and provider identity never change once the row exists. Named first
        // alphabetically on purpose: PostgreSQL fires triggers for the same event in name
        // order, so a re-pointed row is refused for what it is before anything else looks
        // at it.
        //
        // IS DISTINCT FROM, so writing a column back with the value it already holds is not
        // a change — an ORM save that sends every column must not be refused.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION chk_social_identity_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.user_id IS DISTINCT FROM OLD.user_id
                    OR NEW.provider IS DISTINCT FROM OLD.provider
                    OR NEW.provider_subject IS DISTINCT FROM OLD.provider_subject THEN
                    RAISE EXCEPTION 'chk_social_identity_immutable' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement(
            'CREATE TRIGGER chk_social_identity_immutable '.
            'BEFORE UPDATE OF user_id, provider, provider_subject ON social_identities '.
            'FOR EACH ROW EXECUTE FUNCTION chk_social_identity_immutable()'
        );

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION chk_social_identity_not_admin() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                owner_type varchar(20);
            BEGIN
                IF NEW.unlinked_at IS NULL THEN
                    SELECT account_type INTO owner_type FROM users WHERE id = NEW.user_id FOR SHARE;

                    IF owner_type = 'admin' THEN
                        RAISE EXCEPTION 'chk_social_identity_not_admin' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement(
            'CREATE TRIGGER chk_social_identity_not_admin '.
            'BEFORE INSERT OR UPDATE ON social_identities '.
            'FOR EACH ROW EXECUTE FUNCTION chk_social_identity_not_admin()'
        );

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION chk_users_admin_without_linked_social_identity() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM social_identities
                    WHERE user_id = NEW.id AND unlinked_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'chk_users_admin_without_linked_social_identity' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement(
            'CREATE TRIGGER chk_users_admin_without_linked_social_identity '.
            'BEFORE UPDATE OF account_type ON users '.
            "FOR EACH ROW WHEN (NEW.account_type = 'admin') ".
            'EXECUTE FUNCTION chk_users_admin_without_linked_social_identity()'
        );
    }

    /**
     * SQLite: triggers, as for every other invariant this platform keeps on that engine.
     *
     * No lock is needed here. SQLite admits one writer at a time, so a link and a
     * promotion cannot interleave the way they can on PostgreSQL.
     */
    private function applySqliteInvariants(): void
    {
        // Ownership and provider identity never change. IS NOT is SQLite's null-safe
        // comparison, so writing back an unchanged value is not refused.
        DB::statement(
            'CREATE TRIGGER chk_social_identity_immutable '.
            'BEFORE UPDATE OF user_id, provider, provider_subject ON social_identities '.
            'FOR EACH ROW WHEN NEW.user_id IS NOT OLD.user_id '.
            'OR NEW.provider IS NOT OLD.provider '.
            'OR NEW.provider_subject IS NOT OLD.provider_subject '.
            "BEGIN SELECT RAISE(ABORT, 'chk_social_identity_immutable'); END"
        );

        foreach (['INSERT', 'UPDATE'] as $event) {
            DB::statement(
                'CREATE TRIGGER chk_social_identity_not_admin_'.strtolower($event).
                " BEFORE {$event} ON social_identities ".
                'FOR EACH ROW WHEN NEW.unlinked_at IS NULL '.
                "AND (SELECT account_type FROM users WHERE id = NEW.user_id) = 'admin' ".
                "BEGIN SELECT RAISE(ABORT, 'chk_social_identity_not_admin'); END"
            );
        }

        DB::statement(
            'CREATE TRIGGER chk_users_admin_without_linked_social_identity '.
            'BEFORE UPDATE OF account_type ON users '.
            "FOR EACH ROW WHEN NEW.account_type = 'admin' ".
            'AND EXISTS (SELECT 1 FROM social_identities WHERE user_id = NEW.id AND unlinked_at IS NULL) '.
            "BEGIN SELECT RAISE(ABORT, 'chk_users_admin_without_linked_social_identity'); END"
        );
    }
};
