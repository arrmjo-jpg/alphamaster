<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KINDS = "'urls', 'tags', 'prefixes', 'hosts', 'everything'";

    private const STATUSES = "'pending', 'processing', 'succeeded', 'failed'";

    /**
     * Every edge invalidation the platform was asked for, and what became of it (ADR 0053 §4).
     *
     * A row rather than a cache entry, because the state of a purge is something an operator
     * has to be able to see — a purge that failed leaves stale content behind, and ADR 0036
     * requires that state to be visible rather than lost with an expiring key. One row per
     * vendor call: an invalidation larger than the vendor accepts in one call is split
     * before it is stored, so each row succeeds or fails on its own.
     */
    public function up(): void
    {
        Schema::create('cdn_purge_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('integration_provider_id')->nullable()
                ->constrained('integration_providers')->nullOnDelete();
            $table->string('driver', 50);
            $table->string('kind', 20);
            $table->json('items');
            $table->unsignedInteger('item_count');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'available_at'], 'idx_cdn_purge_status_available');
            $table->index('created_at', 'idx_cdn_purge_created');
        });

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => $this->checkConstraints(),
            'sqlite' => $this->sqliteTriggers(),
            default => null,
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('cdn_purge_requests');
    }

    private function checkConstraints(): void
    {
        DB::statement('ALTER TABLE cdn_purge_requests ADD CONSTRAINT chk_cdn_purge_kind CHECK (kind IN ('.self::KINDS.'))');
        DB::statement('ALTER TABLE cdn_purge_requests ADD CONSTRAINT chk_cdn_purge_status CHECK (status IN ('.self::STATUSES.'))');
        DB::statement('ALTER TABLE cdn_purge_requests ADD CONSTRAINT chk_cdn_purge_attempts CHECK (attempts >= 0)');
    }

    private function sqliteTriggers(): void
    {
        $rules = [
            'chk_cdn_purge_kind' => 'NEW.kind NOT IN ('.self::KINDS.')',
            'chk_cdn_purge_status' => 'NEW.status NOT IN ('.self::STATUSES.')',
        ];

        foreach ($rules as $name => $violation) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement(
                    'CREATE TRIGGER '.$name.'_'.strtolower($event).' BEFORE '.$event.' ON cdn_purge_requests '.
                    'FOR EACH ROW WHEN '.$violation.' '.
                    "BEGIN SELECT RAISE(ABORT, '{$name}'); END"
                );
            }
        }
    }
};
