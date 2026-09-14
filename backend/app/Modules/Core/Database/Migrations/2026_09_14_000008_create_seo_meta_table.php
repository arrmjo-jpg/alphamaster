<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-locale search and sharing metadata for any model (ADR 0032, implemented by ADR 0055).
 *
 * One row per owner and locale rather than a column per language, so a language added in
 * Language Management needs no migration. The og image is a media reference, never a path:
 * removing the file clears the reference and leaves the metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_meta', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('seoable_type', 100);
            $table->ulid('seoable_id');
            $table->string('locale', 10);

            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('robots', 50)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('og_title', 255)->nullable();
            $table->text('og_description')->nullable();
            $table->foreignUlid('og_media_id')->nullable()->constrained('media_files')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['seoable_type', 'seoable_id', 'locale'], 'idx_seo_meta_owner_locale');
            $table->index(['seoable_type', 'seoable_id'], 'idx_seo_meta_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_meta');
    }
};
