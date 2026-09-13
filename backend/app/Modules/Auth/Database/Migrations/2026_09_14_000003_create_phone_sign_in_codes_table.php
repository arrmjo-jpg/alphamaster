<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time codes for signing in or registering with a phone number (ADR 0051 §1).
     *
     * Keyed by the number rather than by an account, because the same code serves a
     * number that belongs to nobody yet. The number is held only as the keyed lookup
     * digest `users` uses: this row is not the place for a second copy of personal data.
     */
    public function up(): void
    {
        Schema::create('phone_sign_in_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // One outstanding code per number. A new send replaces it.
            $table->string('phone_hash', 64)->unique();

            // Only the hash: a database read cannot yield a usable code.
            $table->string('otp_hash');

            $table->timestampTz('expires_at')->index();
            $table->timestampTz('sent_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_sign_in_codes');
    }
};
