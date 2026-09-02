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
        Schema::create('languages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 10)->unique('idx_languages_code_unique');
            $table->string('name', 100);
            $table->string('native_name', 100);
            $table->string('direction', 3)->default('ltr');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'idx_languages_active_sort');
        });

        // Enforce at the PostgreSQL engine level that only a single row may have is_default = true
        DB::statement('CREATE UNIQUE INDEX idx_languages_single_default ON languages (is_default) WHERE is_default = TRUE;');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
