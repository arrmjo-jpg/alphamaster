<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A proposed translation, waiting for somebody to read it.
     *
     * The table exists because ADR 0044 §5 says AI proposes and a person accepts. A
     * suggestion that was applied directly would need no row at all — it would be a
     * translation — and that is exactly the design the ADR rejects: the audit trail
     * would record a system actor for content nobody read, and the one question a
     * trail exists to answer becomes unanswerable.
     *
     * It is addressed the way the workshop addresses anything: source key, item id,
     * field, locale (ADR 0043). Nothing here knows what a role or a template is.
     *
     * `source_text` and `existing_text` are snapshots taken when the suggestion was
     * requested, and they are what makes accepting safe. The first is what was
     * translated from, so a reviewer can see the pair without a second query. The
     * second is what the target held at the time — compared again at accept, so a
     * suggestion cannot quietly overwrite a translation somebody wrote in between.
     */
    public function up(): void
    {
        Schema::create('translation_suggestions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Who asked. A suggestion costs money and proposes content, so the request
            // has an owner — and the list an operator sees is their own.
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // The workshop's address for a field, kept as opaque strings. The source
            // key is a registry key, not a table name, so nothing here has to change
            // when a fourth module becomes translatable.
            $table->string('source_key', 64);
            $table->string('item_id', 200);
            $table->string('field', 64);
            $table->string('locale', 16);

            $table->string('status', 20);

            $table->text('source_text');
            $table->text('existing_text')->nullable();
            $table->text('suggestion')->nullable();

            // Why nothing came back, in the vendor's own words. Never a prompt and
            // never an answer — those are the two things a diagnostic must not store
            // outside the content's own table.
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();

            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();

            // Whether the accepted text differed from what was proposed. Provenance
            // rather than decoration: "a person wrote this" and "a person let this
            // through" are different facts about the same row.
            $table->boolean('edited')->default(false);

            $table->timestampsTz();

            // One live suggestion per field per language. A second would give a
            // reviewer two answers with no way to tell which the button applies.
            $table->unique(['source_key', 'item_id', 'field', 'locale'], 'uniq_translation_suggestion_target');

            $table->index(['locale', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_suggestions');
    }
};
