<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-locale values for the settings that hold text a visitor reads.
 *
 * ADR 0018 rejects `site_name_ar` / `site_name_en` outright: the language set is
 * administrator-managed at runtime (ADR 0015), so a key per language would make
 * adding a language a migration. The value moves into a row instead, following the
 * relational pattern ADR 0015 §5 established and `notification_template_translations`
 * and `role_translations` already run.
 *
 * The base `settings.value` column stays and remains the last step of the fallback
 * chain, so a setting with no translation at all is still readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('setting_id')->constrained('settings')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->text('value')->nullable();
            $table->timestampsTz();

            $table->unique(['setting_id', 'locale'], 'idx_setting_translation_locale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_translations');
    }
};
