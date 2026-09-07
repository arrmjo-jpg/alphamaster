<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give an account a phone number it can sign in with.
     *
     * The platform has never had one. A number existed only encrypted on an MFA
     * method row, which is a second factor rather than an identity, and reachable only
     * by an account that had already enrolled — so it could not serve as a login
     * identifier without making sign-in depend on MFA state.
     *
     * Two columns, and the split is the point.
     *
     * `phone` is the canonical E.164 value: what an operator reads, and what a message
     * is addressed to.
     *
     * `phone_hash` is what the database constrains and what the login path queries. It
     * carries the unique index rather than `phone` doing so, which keeps both the
     * constraint and the lookup independent of how `phone` is stored. It is a keyed
     * digest, so it also cannot be matched against a precomputed table of numbers.
     *
     * Nullable, and unique over the hash: PostgreSQL and SQLite both allow any number
     * of NULLs under a unique index, so every account that has no phone coexists while
     * no two accounts can claim the same one. That is the correct shape here — a phone
     * is optional on this platform and mandatory for nobody.
     *
     * Deliberately no index on `phone` itself. Nothing queries it; adding one would be
     * a second thing to keep in step for a lookup that never happens.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // 15 digits is E.164's maximum, plus the leading `+`.
            $table->string('phone', 16)->nullable()->after('email');

            // 64 hexadecimal characters: the full width of a SHA-256 digest. Not
            // truncated — the column is unique, and truncation trades collision
            // probability for nothing, since the width costs the same either way.
            $table->string('phone_hash', 64)->nullable()->unique()->after('phone');
        });
    }

    /**
     * Reverse the migration.
     *
     * The unique index goes first and explicitly. Dropping a column out from under its
     * own index is a difference between engines rather than a guarantee, and this
     * migration is exercised on both.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone_hash']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'phone_hash']);
        });
    }
};
