<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hardcoded rather than derived from the enums: a migration is a historical
     * snapshot and must not change retroactively when they evolve.
     *
     * @var array<int, string>
     */
    private const VISIBILITIES = ['public', 'private'];

    /**
     * @var array<int, string>
     */
    private const STATUSES = ['uploaded', 'scanning', 'processing', 'ready', 'scan_failed', 'processing_failed'];

    /**
     * @var array<int, string>
     */
    private const SCAN_STATUSES = ['not_scanned', 'pending', 'clean', 'infected', 'scan_error'];

    /**
     * @var array<int, string>
     */
    private const TYPES = ['image', 'video', 'audio', 'document'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // What the file is attached to. Nullable so an upload can exist before the
            // record it belongs to does, which is how most upload flows actually work.
            $table->nullableUlidMorphs('attachable');
            // A named bucket within an owner, e.g. 'avatar' or 'submissions'.
            $table->string('collection', 60)->default('default');

            // Where the bytes are. The disk is stored per row rather than globally so a
            // later migration between backends can proceed file by file.
            $table->string('disk', 50);
            $table->string('path', 500);

            $table->string('original_filename', 255);
            // Detected from the bytes, never taken from the client.
            $table->string('mime_type', 150);
            $table->string('extension', 20)->nullable();
            $table->string('type', 20);
            $table->unsignedBigInteger('size_bytes');
            // Content hash: identifies duplicates and detects tampering at rest.
            $table->string('checksum', 64)->index();

            $table->string('visibility', 20)->default('private');
            $table->string('status', 30)->default('uploaded');
            $table->string('scan_status', 20)->default('not_scanned');
            $table->text('failure_reason')->nullable();

            // Populated by processors. Absent until one runs, which for images and
            // video means until the container gains the extensions to do it.
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('metadata')->nullable();

            // Who uploaded it. Retained when the account goes, so an audit of what was
            // uploaded does not evaporate with the uploader.
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();
            // Soft deleted first; the bytes are purged by an explicit job, never as a
            // side effect of a row disappearing.
            $table->softDeletesTz();

            $table->index(['attachable_type', 'attachable_id', 'collection'], 'idx_media_attachable_collection');
            $table->index(['status', 'created_at'], 'idx_media_status_created');
        });

        $this->applyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }

    /**
     * Constrain the enum-backed columns at the database level, as every other module
     * does, so the invariants survive raw writes and disabled model events.
     */
    private function applyConstraints(): void
    {
        $rules = [
            'chk_media_files_visibility' => ['visibility', self::VISIBILITIES],
            'chk_media_files_status' => ['status', self::STATUSES],
            'chk_media_files_scan_status' => ['scan_status', self::SCAN_STATUSES],
            'chk_media_files_type' => ['type', self::TYPES],
        ];

        $driver = Schema::getConnection()->getDriverName();

        foreach ($rules as $name => [$column, $values]) {
            $quoted = implode(', ', array_map(static fn (string $v): string => "'".$v."'", $values));

            match ($driver) {
                'pgsql' => DB::statement(
                    "ALTER TABLE media_files ADD CONSTRAINT {$name} CHECK ({$column} IN ({$quoted}))"
                ),
                'sqlite' => $this->applySqliteTrigger($name, $column, $quoted),
                default => throw new RuntimeException(
                    "The media invariants have no implementation for the [{$driver}] driver."
                ),
            };
        }
    }

    /**
     * SQLite has no ALTER TABLE ADD CONSTRAINT, so triggers enforce the same rules.
     */
    private function applySqliteTrigger(string $name, string $column, string $quoted): void
    {
        foreach (['INSERT', 'UPDATE'] as $event) {
            DB::statement(
                'CREATE TRIGGER '.$name.'_'.strtolower($event).
                " BEFORE {$event} ON media_files ".
                "FOR EACH ROW WHEN NEW.{$column} NOT IN ({$quoted}) ".
                "BEGIN SELECT RAISE(ABORT, '{$name}'); END"
            );
        }
    }
};
