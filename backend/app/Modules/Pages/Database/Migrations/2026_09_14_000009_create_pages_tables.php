<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Static pages (ADR 0055).
 *
 * One page, its translations, and the slugs it used to have. Nothing about a language is a
 * column: the page carries what is the same in every language, and each translation row
 * carries one language's text and address. `UNIQUE(page_id, locale)` keeps one translation
 * per language; `UNIQUE(locale, slug)` keeps an address unambiguous within a language while
 * letting two languages share one.
 */
return new class extends Migration
{
    private const STATUSES = "'draft', 'published', 'archived'";

    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'published_at'], 'idx_pages_status_published');
            $table->index('sort_order', 'idx_pages_sort_order');
        });

        Schema::create('page_translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title', 200)->nullable();
            $table->string('slug', 190)->nullable();
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->timestampsTz();

            $table->unique(['page_id', 'locale'], 'idx_page_translation_locale');
            $table->unique(['locale', 'slug'], 'idx_page_translation_slug');
        });

        Schema::create('page_slug_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('slug', 190);
            $table->timestampTz('created_at')->nullable();

            $table->unique(['locale', 'slug'], 'idx_page_slug_history');
            $table->index('page_id', 'idx_page_slug_history_page');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pages ADD CONSTRAINT chk_pages_status CHECK (status IN ('.self::STATUSES.'))');

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement(
                    'CREATE TRIGGER chk_pages_status_'.strtolower($event).' BEFORE '.$event.' ON pages '.
                    'FOR EACH ROW WHEN NEW.status NOT IN ('.self::STATUSES.') '.
                    "BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed: chk_pages_status'); END"
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_slug_history');
        Schema::dropIfExists('page_translations');
        Schema::dropIfExists('pages');
    }
};
