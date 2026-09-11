<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who accepted a suggestion (ADR 0048 §6).
     *
     * `requested_by` has recorded who asked since the table was created; the person who
     * decided was recorded nowhere. Null for every suggestion not accepted, and for any
     * accepted before this column existed — which is the honest answer, not a gap to
     * backfill with a guess.
     */
    public function up(): void
    {
        Schema::table('translation_suggestions', function (Blueprint $table): void {
            $table->foreignUlid('accepted_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('translation_suggestions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accepted_by');
        });
    }
};
