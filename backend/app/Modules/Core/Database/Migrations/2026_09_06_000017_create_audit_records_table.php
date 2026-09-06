<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The administrative audit trail (ADR 0037).
 *
 * Append-only by design: there is no `updated_at`, because a row that can be
 * updated is a row whose subject can rewrite it, and the accounts with the most
 * reason to do that are exactly the ones with administrative access.
 *
 * `context` holds what changed. It never holds a secret in any form — not
 * plaintext, ciphertext, a partial value, a hash or a length. A ciphertext here
 * would be a second copy of the credential with a longer retention period and
 * weaker access control than the one in `settings`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Resolved at the time of the action. Nullable because a console command
            // or the scheduler acts with no user, and recording that honestly is
            // better than attributing it to whoever happened to run the deploy.
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // A stable identifier — `setting.updated`, `secret.rotated` — never a
            // sentence assembled for display, so the trail stays queryable and
            // survives its wording changing.
            $table->string('action', 100);

            // What was acted on: a setting reference, a provider id, a namespace.
            $table->string('subject', 191)->nullable();

            $table->string('outcome', 20);
            $table->json('context')->nullable();

            // Joins a row to its log lines (ADR 0023).
            $table->string('correlation_id', 64)->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['action', 'created_at'], 'idx_audit_action_created');
            $table->index(['subject', 'created_at'], 'idx_audit_subject_created');
            $table->index('actor_id', 'idx_audit_actor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_records');
    }
};
