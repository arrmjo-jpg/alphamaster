<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('group', 50)->index();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->boolean('is_secret')->default(false);
            $table->boolean('is_public')->default(false);
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->unique(['group', 'key'], 'idx_settings_group_key_unique');
            $table->index(['group', 'is_public'], 'idx_settings_group_public');
        });

        // PostgreSQL check constraint: Secret settings are NEVER public
        DB::statement('ALTER TABLE settings ADD CONSTRAINT chk_settings_secret_never_public CHECK (NOT (is_secret = TRUE AND is_public = TRUE));');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
