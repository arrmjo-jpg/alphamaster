<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = "'pending', 'processing', 'completed', 'inconclusive', 'unsupported', 'failed', 'cancelled'";

    private const CLASSIFICATIONS = "'likely_synthetic', 'likely_authentic', 'inconclusive'";

    private const DECISIONS = "'confirmed_synthetic', 'confirmed_authentic', 'undetermined'";

    /**
     * Analyses of stored media, and people's reviews of them (ADR 0054).
     *
     * Append-only by design: a later analysis supersedes an earlier one by being pointed at,
     * and a review is its own row rather than an edit. No boolean records whether media is
     * generated, because no analyzer can support that claim; scores, their types and the
     * versions that produced them are recorded instead.
     *
     * An analysis follows its media file: removing the file for good removes its analyses.
     */
    public function up(): void
    {
        Schema::create('media_analyses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->string('consumer', 100);
            $table->json('types');
            $table->string('status', 20)->default('pending');
            $table->string('classification', 20)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('scores')->nullable();
            $table->json('unsupported_types')->nullable();
            $table->json('signals')->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('analyzer', 100)->nullable();
            $table->string('model_version', 100)->nullable();
            $table->string('policy_version', 40);
            $table->string('input_fingerprint', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->ulid('reanalysis_of')->nullable();
            $table->ulid('superseded_by')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['media_file_id', 'created_at'], 'idx_media_analyses_media_created');
            $table->index(['media_file_id', 'input_fingerprint'], 'idx_media_analyses_fingerprint');
            $table->index(['status', 'available_at'], 'idx_media_analyses_status_available');
            $table->index('created_at', 'idx_media_analyses_created');
        });

        // The two links between analyses point at this same table, so they are added once
        // the table and its primary key exist: in the create itself PostgreSQL would be asked
        // to reference a key that has not been declared yet.
        Schema::table('media_analyses', function (Blueprint $table): void {
            $table->foreign('reanalysis_of', 'fk_media_analyses_reanalysis_of')
                ->references('id')->on('media_analyses')->nullOnDelete();
            $table->foreign('superseded_by', 'fk_media_analyses_superseded_by')
                ->references('id')->on('media_analyses')->nullOnDelete();
        });

        Schema::create('media_analysis_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('media_analysis_id')->constrained('media_analyses')->cascadeOnDelete();
            $table->foreignUlid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 30);
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['media_analysis_id', 'created_at'], 'idx_media_analysis_reviews_analysis');
        });

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => $this->checkConstraints(),
            'sqlite' => $this->sqliteTriggers(),
            default => null,
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('media_analysis_reviews');
        Schema::dropIfExists('media_analyses');
    }

    private function checkConstraints(): void
    {
        DB::statement('ALTER TABLE media_analyses ADD CONSTRAINT chk_media_analyses_status CHECK (status IN ('.self::STATUSES.'))');
        DB::statement('ALTER TABLE media_analyses ADD CONSTRAINT chk_media_analyses_classification CHECK (classification IS NULL OR classification IN ('.self::CLASSIFICATIONS.'))');
        DB::statement('ALTER TABLE media_analyses ADD CONSTRAINT chk_media_analyses_confidence CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1))');
        DB::statement('ALTER TABLE media_analysis_reviews ADD CONSTRAINT chk_media_analysis_reviews_decision CHECK (decision IN ('.self::DECISIONS.'))');
    }

    private function sqliteTriggers(): void
    {
        $rules = [
            'chk_media_analyses_status' => ['media_analyses', 'NEW.status NOT IN ('.self::STATUSES.')'],
            'chk_media_analyses_classification' => ['media_analyses', 'NEW.classification IS NOT NULL AND NEW.classification NOT IN ('.self::CLASSIFICATIONS.')'],
            'chk_media_analysis_reviews_decision' => ['media_analysis_reviews', 'NEW.decision NOT IN ('.self::DECISIONS.')'],
        ];

        foreach ($rules as $name => [$table, $violation]) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement(
                    'CREATE TRIGGER '.$name.'_'.strtolower($event).' BEFORE '.$event.' ON '.$table.' '.
                    'FOR EACH ROW WHEN '.$violation.' '.
                    "BEGIN SELECT RAISE(ABORT, '{$name}'); END"
                );
            }
        }
    }
};
