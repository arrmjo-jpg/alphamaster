<?php

declare(strict_types=1);

use App\Modules\Auth\Models\PhoneSignInCode;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Jobs\PruneIntegrationUsageLogs;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Media\Jobs\PurgeDeletedMedia;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * What the scheduler container runs (ADR 0051 §6).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
});

function scheduledEvent(string $needle): object
{
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->description, $needle) || str_contains((string) $event->command, $needle)) {
            return $event;
        }
    }

    throw new RuntimeException("Nothing scheduled matches [{$needle}].");
}

test('each maintenance task is scheduled daily', function (string $needle): void {
    expect(scheduledEvent($needle)->expression)->toBe('0 0 * * *');
})->with(['media:purge-deleted', 'integrations:prune-usage', 'auth:prune-codes', 'auth:clear-resets']);

test('the purge and the prune run with the configured retention', function (): void {
    Queue::fake();
    $settings = app(SettingServiceInterface::class);
    $settings->set('operations', 'media_retention_days', 45);
    $settings->set('operations', 'integration_usage_retention_days', 120);
    Cache::flush();

    scheduledEvent('media:purge-deleted')->run(app());
    scheduledEvent('integrations:prune-usage')->run(app());

    Queue::assertPushed(PurgeDeletedMedia::class, fn (PurgeDeletedMedia $job): bool => $job->retentionDays === 45);
    Queue::assertPushed(PruneIntegrationUsageLogs::class, fn (PruneIntegrationUsageLogs $job): bool => $job->retentionDays === 120);
});

test('provider activity older than the window is pruned, and newer activity is kept', function (): void {
    $this->seed(IntegrationProviderSeeder::class);
    $provider = IntegrationProvider::query()->where('driver', 'log')->firstOrFail();

    foreach ([200, 91, 10, 0] as $daysAgo) {
        DB::table('integration_usage_logs')->insert([
            'id' => (string) Str::ulid(),
            'integration_provider_id' => $provider->id,
            'capability' => 'sms',
            'driver' => 'log',
            'status' => 'success',
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    (new PruneIntegrationUsageLogs(90))->handle();

    expect(DB::table('integration_usage_logs')->count())->toBe(2);
});

test('expired one-time codes are removed and live ones are kept', function (): void {
    PhoneSignInCode::query()->create([
        'phone_hash' => str_repeat('a', 64), 'otp_hash' => 'x', 'expires_at' => now()->subMinute(), 'sent_at' => now()->subMinutes(6),
    ]);
    PhoneSignInCode::query()->create([
        'phone_hash' => str_repeat('b', 64), 'otp_hash' => 'x', 'expires_at' => now()->addMinute(), 'sent_at' => now(),
    ]);

    $this->artisan('auth:prune-codes')->assertSuccessful();

    expect(PhoneSignInCode::query()->pluck('phone_hash')->all())->toBe([str_repeat('b', 64)]);
});
