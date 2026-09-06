<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A write counter per setting, for optimistic concurrency (ADR 0038).
 *
 * The record allowed either `updated_at` or a counter, "where a timestamp's
 * resolution is not sufficient to distinguish two writes". It is not: `timestampsTz`
 * stores whole seconds, so two administrators saving the same group a moment apart
 * produce an identical validator and the second silently overwrites the first —
 * which is the exact loss the precondition exists to prevent.
 *
 * A counter also keeps values out of the validator entirely. Deriving it from the
 * stored data would mean hashing ciphertext into something handed to a client, and
 * an integer that only ever counts writes raises no such question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('version')->default(0)->after('is_localized');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
