<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A translation of one item into one language, as the operator sees it (ADR 0056).
     *
     * Suggestions were per field, and so was every decision about them — which is how accepting
     * a notification subject on its own reached a template that refuses a subject without its
     * body. The batch is the item: it is requested, generated, reviewed and accepted as one, and
     * the per-field rows beneath it stay what a job works on.
     *
     * One batch per item per language, like one suggestion per field per language before it: a
     * second would give a reviewer two answers with no way to tell which the button applies, and
     * pressing Translate twice must not cost twice. A decided batch is reused by the next request
     * rather than accumulated; the audit trail is where history lives.
     */
    public function up(): void
    {
        Schema::create('translation_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            // The workshop's address for an item, as opaque strings (ADR 0043).
            $table->string('source_key', 64);
            $table->string('item_id', 200);
            $table->string('locale', 16);

            $table->string('status', 20);

            $table->unsignedSmallInteger('fields_total')->default(0);
            $table->unsignedSmallInteger('fields_ready')->default(0);
            $table->unsignedSmallInteger('fields_failed')->default(0);

            // The first field's reason, already redacted, so a list can say why without
            // reading every row beneath it.
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();

            $table->boolean('edited')->default(false);

            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['source_key', 'item_id', 'locale'], 'uniq_translation_batch_target');
            $table->index(['locale', 'status']);
        });

        Schema::table('translation_suggestions', function (Blueprint $table): void {
            $table->foreignUlid('batch_id')->nullable()->after('id')->constrained('translation_batches')->cascadeOnDelete();

            // The field as its source described it when the translation was asked for, so
            // the job translates it the right way without the source being read again.
            $table->string('field_label', 200)->nullable();
            $table->string('field_type', 20)->default('plain_text');
            $table->string('field_group', 20)->default('content');
            $table->boolean('required')->default(true);
            $table->unsignedInteger('max_length')->nullable();
        });

        $this->foldSuggestionsIntoBatches();
    }

    public function down(): void
    {
        Schema::table('translation_suggestions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('batch_id');
            $table->dropColumn(['field_label', 'field_type', 'field_group', 'required', 'max_length']);
        });

        Schema::dropIfExists('translation_batches');
    }

    /**
     * Every suggestion already stored belongs to an item, so each item gets its batch.
     *
     * Its state is the one an operator would read from the fields: still waiting if any is,
     * failed if any failed, ready if every one came back, and decided only if all were.
     */
    private function foldSuggestionsIntoBatches(): void
    {
        $targets = DB::table('translation_suggestions')
            ->select('source_key', 'item_id', 'locale')
            ->groupBy('source_key', 'item_id', 'locale')
            ->get();

        foreach ($targets as $target) {
            $rows = DB::table('translation_suggestions')
                ->where('source_key', $target->source_key)
                ->where('item_id', $target->item_id)
                ->where('locale', $target->locale)
                ->get();

            $statuses = $rows->pluck('status')->map(fn ($status): string => (string) $status)->all();

            $status = match (true) {
                in_array('pending', $statuses, true) => 'pending',
                in_array('failed', $statuses, true) => 'failed',
                in_array('ready', $statuses, true) => 'ready',
                in_array('accepted', $statuses, true) => 'accepted',
                default => 'dismissed',
            };

            $failed = $rows->firstWhere('status', 'failed');
            $id = (string) Str::ulid();

            DB::table('translation_batches')->insert([
                'id' => $id,
                'requested_by' => $rows->pluck('requested_by')->filter()->first(),
                'accepted_by' => $rows->pluck('accepted_by')->filter()->first(),
                'source_key' => $target->source_key,
                'item_id' => $target->item_id,
                'locale' => $target->locale,
                'status' => $status,
                'fields_total' => $rows->count(),
                'fields_ready' => $rows->whereIn('status', ['ready', 'accepted'])->count(),
                'fields_failed' => $rows->where('status', 'failed')->count(),
                'error_code' => $failed?->error_code,
                'error_message' => $failed?->error_message,
                'edited' => $rows->contains(fn ($row): bool => (bool) $row->edited),
                'completed_at' => $rows->max('completed_at'),
                'resolved_at' => $rows->max('resolved_at'),
                'created_at' => $rows->min('created_at') ?? now(),
                'updated_at' => $rows->max('updated_at') ?? now(),
            ]);

            DB::table('translation_suggestions')
                ->where('source_key', $target->source_key)
                ->where('item_id', $target->item_id)
                ->where('locale', $target->locale)
                ->update(['batch_id' => $id]);
        }
    }
};
