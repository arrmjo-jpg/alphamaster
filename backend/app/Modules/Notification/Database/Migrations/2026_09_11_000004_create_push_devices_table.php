<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a push can reach somebody.
     *
     * A registry rather than a column on `users`, because the second device arrives
     * immediately: one account, a phone and a tablet (ADR 0045 §5). It lives in the
     * Notification module because a push token is a *delivery address*, which is what
     * this module already stores — `notification_preferences` is keyed by `user_id` in
     * the same way, and Notification reaches a recipient's phone number through a Core
     * contract rather than by importing User.
     *
     * `device_id` is generated and kept by the client. It is what makes a token
     * *replaceable*: FCM rotates tokens, and without a stable handle for the handset a
     * rotation would leave two rows and the device would receive everything twice.
     *
     * `access_token_id` is the session the device registered under. Signing out deletes
     * the token, and a device registered during that session stops receiving with it —
     * a shared handset must not keep delivering the previous account's notifications.
     */
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            // The vendor's registration token. Long: FCM's are around 160 characters
            // today and the vendor has lengthened them before.
            $table->string('token', 512);

            $table->string('device_id', 128);
            $table->string('platform', 16);

            // A name a person would recognise in a list of their own devices. Supplied
            // by the client, never trusted for anything but display.
            $table->string('label', 120)->nullable();

            // Sanctum's token id, so signing out can remove exactly the devices that
            // session registered. Nullable and null-on-delete: a device may outlive the
            // session that created it if the client re-registers on a new one, and the
            // row must not vanish because a token expired.
            $table->string('access_token_id')->nullable();

            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            // One row per handset per account. A second would deliver twice.
            $table->unique(['user_id', 'device_id'], 'uniq_push_device_per_account');

            // The send path reads by account; the pruning path reads by staleness.
            $table->index(['user_id']);
            $table->index(['last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
