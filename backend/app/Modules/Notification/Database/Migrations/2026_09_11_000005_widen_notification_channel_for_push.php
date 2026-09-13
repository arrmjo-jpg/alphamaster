<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a preference row name the push channel.
     *
     * `notification_preferences.channel` is constrained at the database level — a CHECK
     * on PostgreSQL, BEFORE triggers on SQLite — to the channels that existed when the
     * table was created. Adding a case to the enum does not widen that list, so without
     * this the first push preference is refused by the database and the failure reads
     * as a constraint violation with no obvious cause.
     *
     * The values are hardcoded rather than read from the enum, as every other
     * constraint migration in this repository does and for the same reason: a migration
     * is a historical snapshot, and one deriving its constraint from today's enum would
     * rewrite the past every time the enum changed.
     */
    private const CHANNELS_BEFORE = ['database', 'mail', 'sms'];

    /**
     * @var array<int, string>
     */
    private const CHANNELS_AFTER = ['database', 'mail', 'sms', 'push'];

    public function up(): void
    {
        $this->rewrite(self::CHANNELS_AFTER);
    }

    /**
     * Any push preference is removed first, so the table never holds a value its own
     * constraint forbids — which PostgreSQL refuses outright and SQLite accepts
     * silently, the worse of the two because the row survives and nothing says so.
     */
    public function down(): void
    {
        DB::table('notification_preferences')->where('channel', 'push')->delete();

        $this->rewrite(self::CHANNELS_BEFORE);
    }

    /**
     * @param  array<int, string>  $channels
     */
    private function rewrite(array $channels): void
    {
        $quoted = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            $channels
        ));

        $driver = Schema::getConnection()->getDriverName();

        match ($driver) {
            'pgsql' => $this->rewriteCheck($quoted),
            'sqlite' => $this->rewriteTriggers($quoted),
            default => throw new RuntimeException(
                "The notification channel constraint has no implementation for the [{$driver}] driver."
            ),
        };
    }

    private function rewriteCheck(string $channels): void
    {
        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT IF EXISTS chk_notification_preferences_channel');
        DB::statement(
            'ALTER TABLE notification_preferences ADD CONSTRAINT chk_notification_preferences_channel '.
            "CHECK (channel IN ({$channels}))"
        );
    }

    private function rewriteTriggers(string $channels): void
    {
        foreach (['INSERT', 'UPDATE'] as $event) {
            $trigger = 'chk_notification_preferences_channel_'.strtolower($event);

            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            DB::statement(
                "CREATE TRIGGER {$trigger} BEFORE {$event} ON notification_preferences ".
                "FOR EACH ROW WHEN NEW.channel NOT IN ({$channels}) ".
                "BEGIN SELECT RAISE(ABORT, 'chk_notification_preferences_channel'); END"
            );
        }
    }
};
