<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operators' translations of the platform's interface (ADR 0049).
     *
     * The catalogue itself is code: its English files define every key, and files shipped for
     * other languages are their base. This table is what operators write over it — one row per
     * key per language — and a deploy never touches it.
     *
     * `source_hash` is the hash of the English text the translation was written against. When a
     * deploy changes that text, the row still displays, and the workshop counts the key as not
     * translated so it is translated again. Adding a language adds no rows and needs no
     * migration: a language with none reads English.
     */
    public function up(): void
    {
        Schema::create('interface_translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // `console` or `api`: which catalogue the key belongs to.
            $table->string('catalogue', 32);
            // A language code as Language Management stores it. Not a foreign key, so a
            // language's rows outlive any change to the language row.
            $table->string('locale', 16);
            $table->string('key', 255);

            $table->text('value');
            $table->char('source_hash', 64);

            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['catalogue', 'locale', 'key'], 'uniq_interface_translation');
            $table->index(['catalogue', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interface_translations');
    }
};
