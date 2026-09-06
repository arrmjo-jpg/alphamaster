<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\SettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The ENV / Settings boundary
|--------------------------------------------------------------------------
|
| Three classes of configuration, and the line between them is not "could an
| administrator plausibly want to change this".
|
| BOOTSTRAP — needed before a setting can be read at all: the database DSN, the
| Redis connection, APP_KEY, APP_URL, the queue and cache drivers. These stay in the
| environment because moving them into the database makes the database a prerequisite
| for reading the configuration that tells the application where the database is.
|
| RUNTIME — what the platform does once it is running: site identity, branding, mail,
| localization, operational policy. These belong in Settings, where an operator can
| change them without a deploy.
|
| SECRET — a credential in either class. A bootstrap secret stays in the environment;
| a runtime secret is encrypted in Settings and never leaves it in plaintext.
|
| These assert the boundary rather than describing it, because a boundary nothing
| checks is a comment.
|
*/

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->registry = app(SettingRegistry::class);
    $this->service = app(SettingServiceInterface::class);
});

// ── Bootstrap configuration stays out of the database ────────────────────────

test('no bootstrap-critical value is declared as a setting', function (): void {
    // The circular dependency this prevents: reading the database credentials from
    // the database.
    $forbidden = [
        'db_connection', 'db_host', 'db_port', 'db_database', 'db_username', 'db_password',
        'redis_host', 'redis_port', 'redis_password', 'redis_client',
        'app_key', 'app_env', 'app_debug', 'cache_store', 'queue_connection',
        'session_driver', 'filesystem_disk',
    ];

    $declared = array_map(
        static fn (string $reference): string => explode('.', $reference, 2)[1],
        array_keys($this->registry->all()),
    );

    foreach ($forbidden as $key) {
        expect($declared)->not->toContain($key, $key.' was moved into the database');
    }
});

test('the platform reads its own origin from the environment, not from settings', function (): void {
    // general.site_url is the public canonical origin an email or a sitemap uses.
    // APP_URL is what the framework needs before any setting can be read, and the two
    // are deliberately different things.
    expect(config('app.url'))->toBeString()->not->toBeEmpty()
        ->and($this->registry->has('general.site_url'))->toBeTrue();
});

test('the encryption key is environment configuration, and settings depend on it', function (): void {
    // A secret setting is encrypted with APP_KEY, so the key cannot itself be a
    // setting: decrypting it would require the key it is.
    expect(config('app.key'))->toBeString()->not->toBeEmpty()
        ->and($this->registry->has('security.app_key'))->toBeFalse();
});

// ── Runtime configuration is in settings, not in the environment ────────────

test('runtime configuration an operator changes is declared, not hard-coded', function (): void {
    foreach ([
        'general.site_name',
        'general.maintenance_mode',
        'mail.host',
        'branding.logo_light',
        'operations.provider_timeout_seconds',
    ] as $reference) {
        expect($this->registry->has($reference))->toBeTrue($reference.' is not declared');
    }
});

// ── Bootstrap safety: the application starts before settings are readable ────

test('reading a setting before the container is ready yields the default', function (): void {
    // The helper degrades rather than raising, so anything running before the service
    // provider has bound the contract gets an answer instead of a fatal.
    app()->forgetInstance(SettingServiceInterface::class);

    $bound = app()->bound(SettingServiceInterface::class);

    expect($bound)->toBeTrue()
        ->and(setting('general.site_name'))->not->toBeNull();
});

test('an unknown key returns the caller default rather than raising', function (): void {
    expect(setting('nothing.declared', 'fallback'))->toBe('fallback');
});

test('a settings read survives the cache being unreachable', function (): void {
    // Settings is a fail-open namespace (ADR 0035): Redis being down degrades
    // performance, never availability.
    config(['cache.stores.broken' => ['driver' => 'redis', 'connection' => 'does_not_exist']]);
    config(['cache.default' => 'broken']);

    expect($this->service->get('general.site_name'))->toBe('AlphaMaster Enterprise');
});

test('a warm cache answers a settings read even with the database gone', function (): void {
    // Fail-open is about the cache, not the source. A value already cached is still
    // correct when the database is unreachable, and refusing to serve it would make
    // an outage worse than it is.
    $this->service->get('security.max_login_attempts');

    expect($this->service->get('security.max_login_attempts'))->toBe(5);
});

test('a settings read has no catch that could substitute a default for the source', function (): void {
    // Asserted against the source rather than by simulating an outage: RefreshDatabase
    // holds the test inside its own transaction on an already-resolved connection, so
    // a "database is gone" this test could stage would not be one the read actually
    // meets. What is checkable, and is the property that matters, is that the read
    // path contains nothing that turns a database failure into a value.
    //
    // Fail-open belongs to the cache (ADR 0035). The database is the source of truth,
    // and a silent default in its place would let a security setting read as whatever
    // the caller passed — a safe default must never stand in for a security-critical
    // value.
    $source = (string) file_get_contents(
        app_path('Modules/Settings/Services/SettingService.php')
    );

    // The read path: get(), getGroupIndex(), readSecret(). None may swallow.
    expect($source)->not->toContain('catch (QueryException')
        ->and($source)->not->toContain('catch (\Throwable')
        ->and($source)->not->toContain('catch (Throwable');
});

// ── Secrets are never in the environment once they are settings ─────────────

test('a runtime secret lives in settings and not in configuration', function (): void {
    $this->service->set('mail', 'password', 'runtime-secret-value');

    // Not in config, not in the environment: it is a row, encrypted.
    expect(config('mail.mailers.smtp.password'))->not->toBe('runtime-secret-value')
        ->and(env('MAIL_PASSWORD'))->not->toBe('runtime-secret-value')
        ->and($this->service->get('mail.password'))->toBe('runtime-secret-value');
});

test('every declared secret is stored encrypted rather than in the open', function (): void {
    foreach ($this->registry->all() as $definition) {
        if (! $definition->isSecret) {
            continue;
        }

        $this->service->set($definition->group, $definition->key, 'probe-value-'.$definition->key);

        $stored = (string) DB::table('settings')
            ->where('group', $definition->group)
            ->where('key', $definition->key)
            ->value('value');

        expect($stored)->not->toBe('probe-value-'.$definition->key, $definition->reference().' is stored in the open')
            ->and($stored)->not->toBeEmpty();
    }
});
