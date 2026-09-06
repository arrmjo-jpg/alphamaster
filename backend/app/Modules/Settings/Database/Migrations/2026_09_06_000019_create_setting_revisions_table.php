<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a setting used to be, so a rollback has something to restore (ADR 0040).
 *
 * Separate from `audit_records` on purpose. The audit trail answers who changed what
 * and when, and deliberately carries no value; this answers what the value was. ADR
 * 0038 originally claimed the trail could serve both, and could not — the two records
 * are individually right and jointly insufficient, which is the gap this table closes.
 *
 * A secret setting has no row here at all. Not a redacted one, not a null-valued
 * placeholder: a row that exists but is empty is an invitation for someone later to
 * fill it, and the only durable way to keep credentials out of history is for history
 * to have nowhere to put them.
 *
 * There is no `updated_at`. A revision records a state that already happened, and a
 * record of the past that can be edited is not a record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Revisions belong to their setting and go with it. Unlike the audit
            // trail, nothing here is evidence, so there is no reason to outlive the
            // thing it describes.
            $table->foreignUlid('setting_id')->constrained('settings')->cascadeOnDelete();

            // The version this value belonged to — the one before the write that
            // superseded it, so a rollback can name a target unambiguously.
            $table->unsignedBigInteger('version');

            // Null for a setting that is not localized. For one that is, the locale
            // this value belonged to: a localized write replaces one language and
            // leaves the others, so its history is per-language too.
            $table->string('locale', 10)->nullable();

            // The previous value, in its canonical stored form. Never encrypted,
            // because a secret never reaches this table.
            $table->text('value')->nullable();

            // Who superseded it. Nullable because a console command or the
            // synchroniser acts with no user, and recording that honestly beats
            // attributing it to whoever ran the deploy.
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('created_at')->useCurrent();

            // The query history is actually read with: this setting, newest first.
            $table->index(['setting_id', 'created_at'], 'idx_setting_revision_timeline');
            $table->index(['setting_id', 'version'], 'idx_setting_revision_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_revisions');
    }
};
