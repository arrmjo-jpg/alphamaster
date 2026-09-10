<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The pending code that confirms a number.
     *
     * It lives in Auth rather than beside `users.phone_verified_at`, because the two
     * are different things: whether an account's number is confirmed is a property of
     * the account, and the credential material that confirms it is a proof mechanism —
     * the same split that puts `email_verified_at` on the user and MFA codes on
     * `mfa_methods`.
     *
     * A table rather than four more columns on `users`: transient credential material
     * does not belong on the identity row, and a confirmed verification deletes the
     * row whole rather than leaving nulls behind.
     */
    public function up(): void
    {
        Schema::create('phone_verifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // One outstanding verification per account. A second send replaces the
            // first, so two live codes never exist for one person.
            $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();

            // Only the hash. A database read cannot yield a usable code, exactly as
            // with delivered MFA codes and recovery codes.
            $table->string('otp_hash');

            // Which number the code went to, as the same keyed digest `users` uses for
            // lookup. Recorded so that a number changed mid-flight cannot be confirmed
            // by a code that was sent to the previous one — and stored as a digest
            // rather than in the clear, because a phone number is personal data and
            // this row is not the place to keep a second copy of it.
            $table->string('destination_hash');

            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at');

            // Wrong answers against the outstanding code. The code is discarded once
            // this reaches the configured limit, so guessing costs a resend.
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
    }
};
