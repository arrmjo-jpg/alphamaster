<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team directory (ADR 0055).
 *
 * One member, their translations, and the slugs they used to have. The picture is a media
 * reference; removing the file leaves the member without one. Social links are addresses,
 * the same in every language, so they live on the member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignUlid('avatar_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->json('social_links')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['is_active', 'sort_order'], 'idx_team_members_active_order');
        });

        Schema::create('team_member_translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_member_id')->constrained('team_members')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 150)->nullable();
            $table->string('position', 150)->nullable();
            $table->longText('bio')->nullable();
            $table->string('slug', 190)->nullable();
            $table->timestampsTz();

            $table->unique(['team_member_id', 'locale'], 'idx_team_member_translation_locale');
            $table->unique(['locale', 'slug'], 'idx_team_member_translation_slug');
        });

        Schema::create('team_member_slug_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_member_id')->constrained('team_members')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('slug', 190);
            $table->timestampTz('created_at')->nullable();

            $table->unique(['locale', 'slug'], 'idx_team_member_slug_history');
            $table->index('team_member_id', 'idx_team_member_slug_history_member');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_member_slug_history');
        Schema::dropIfExists('team_member_translations');
        Schema::dropIfExists('team_members');
    }
};
