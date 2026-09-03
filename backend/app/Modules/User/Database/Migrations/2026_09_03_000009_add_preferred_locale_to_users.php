<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give an account a language of its own.
     *
     * ADR 0015 places the authenticated user's preferred locale second in the
     * resolution precedence, and LocaleResolver has read `$user->preferred_locale`
     * since Phase 3 — against a column that was never created, so that tier has
     * silently resolved to null ever since. Notifications are the first consumer that
     * genuinely needs it: a message is read by its recipient rather than by whoever
     * triggered it, so it must render in their language and not in the language of
     * the request that happened to cause it.
     *
     * Deliberately no foreign key to languages.code. Locales are administered at
     * runtime and a language can be deactivated or removed; resolution already falls
     * through to the next tier when a stored locale is not currently active, and a
     * constraint here would turn that graceful fallback into a delete-time failure.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('preferred_locale', 10)->nullable()->after('email_verified_at')->index();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('preferred_locale');
        });
    }
};
