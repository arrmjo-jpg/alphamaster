<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A number the platform has confirmed, and the pending code that confirms one.
     *
     * A phone number could already arrive by two routes — an account setting its own,
     * and an administrator setting it for them — and neither confirmed that anybody
     * holds it. Only SMS-MFA enrolment ever proved possession, and that is a second
     * factor rather than a property of the account, so a number on the user row meant
     * nothing more than that somebody typed it.
     *
     * `phone_verified_at` is the same shape as `email_verified_at` deliberately: a
     * moment rather than a flag, so an interface can render *when* and a client can
     * still compare against null.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('phone_verified_at')->nullable()->after('phone_hash');
        });

    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone_verified_at');
        });
    }
};
