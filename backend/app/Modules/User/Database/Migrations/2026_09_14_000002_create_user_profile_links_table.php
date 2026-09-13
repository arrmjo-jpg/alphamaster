<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links an account shows to its public presence elsewhere (ADR 0051 §4).
     *
     * Not social identities: a link proves nothing and signs nobody in, which is why it
     * is a table of its own rather than a column on `social_identities`.
     */
    public function up(): void
    {
        Schema::create('user_profile_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            // The label a client renders the link with. Validated against the
            // application's closed list rather than a CHECK, so adding a platform is a
            // code change and not a migration.
            $table->string('platform', 30);
            $table->string('url', 2048);

            // Where it sits in the list the account chose. Unique per account, so two
            // links cannot claim one place.
            $table->unsignedSmallInteger('position');

            $table->timestampsTz();

            $table->unique(['user_id', 'position'], 'uniq_user_profile_link_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profile_links');
    }
};
