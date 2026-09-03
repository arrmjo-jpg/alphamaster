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
    private const TYPES = ['security.alert', 'account.updated', 'admin.announcement'];

    /**
     * @var array<int, string>
     */
    private const CHANNELS = ['database', 'mail', 'sms'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Laravel's own table, backing the database channel.
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->ulidMorphs('notifiable');
            $table->text('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The notification this template renders, one template per type.
            $table->string('type', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // Relational translations per ADR 0015: a locale is a row, not a JSON key.
        Schema::create('notification_template_translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('notification_template_id')
                ->constrained('notification_templates')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('subject', 255);
            $table->text('body');
            $table->timestampsTz();

            $table->unique(['notification_template_id', 'locale'], 'idx_notification_template_locale');
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('channel', 20);
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            // One decision per recipient, per notification, per channel.
            $table->unique(['user_id', 'type', 'channel'], 'idx_notification_preference_unique');
        });

        $this->applyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_template_translations');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notifications');
    }

    /**
     * Constrain the enum-backed columns at the database level, as every other module
     * does, so the invariants survive raw writes and disabled model events.
     */
    private function applyConstraints(): void
    {
        $types = $this->quote(self::TYPES);
        $channels = $this->quote(self::CHANNELS);
        $driver = Schema::getConnection()->getDriverName();

        match ($driver) {
            'pgsql' => $this->applyCheckConstraints($types, $channels),
            'sqlite' => $this->applySqliteTriggers($types, $channels),
            default => throw new RuntimeException(
                "The notification invariants have no implementation for the [{$driver}] driver."
            ),
        };
    }

    private function applyCheckConstraints(string $types, string $channels): void
    {
        DB::statement(
            'ALTER TABLE notification_templates ADD CONSTRAINT chk_notification_templates_type '.
            "CHECK (type IN ({$types}))"
        );
        DB::statement(
            'ALTER TABLE notification_preferences ADD CONSTRAINT chk_notification_preferences_type '.
            "CHECK (type IN ({$types}))"
        );
        DB::statement(
            'ALTER TABLE notification_preferences ADD CONSTRAINT chk_notification_preferences_channel '.
            "CHECK (channel IN ({$channels}))"
        );
    }

    private function applySqliteTriggers(string $types, string $channels): void
    {
        $rules = [
            'chk_notification_templates_type' => ['notification_templates', "NEW.type NOT IN ({$types})"],
            'chk_notification_preferences_type' => ['notification_preferences', "NEW.type NOT IN ({$types})"],
            'chk_notification_preferences_channel' => ['notification_preferences', "NEW.channel NOT IN ({$channels})"],
        ];

        foreach ($rules as $name => [$table, $violation]) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement(
                    'CREATE TRIGGER '.$name.'_'.strtolower($event).
                    " BEFORE {$event} ON {$table} ".
                    "FOR EACH ROW WHEN {$violation} ".
                    "BEGIN SELECT RAISE(ABORT, '{$name}'); END"
                );
            }
        }
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quote(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'".$v."'", $values));
    }
};
