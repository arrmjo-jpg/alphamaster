<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role labels are created by administrators after deployment, so they cannot
 * live in a language file that ships with the code (ADR 0030). They follow the
 * relational pattern ADR 0015 §5 established and `notification_template_translations`
 * already runs: one row per locale, keyed uniquely on owner and locale.
 *
 * The foreign key is an unsigned big integer rather than a ULID because Spatie
 * creates `roles` with `bigIncrements`, unlike this platform's own tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label', 150);
            $table->timestampsTz();

            $table->unique(['role_id', 'locale'], 'idx_role_translation_locale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_translations');
    }
};
