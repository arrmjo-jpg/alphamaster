<?php

declare(strict_types=1);

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Definitions\SettingSynchronizer;
use App\Modules\Settings\Enums\SettingType;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    // A registry built per test rather than the application's, so these assert the
    // synchroniser's rules rather than the current platform catalogue.
    $this->registry = new SettingRegistry;
    $this->sync = fn (): mixed => (new SettingSynchronizer(
        $this->registry,
        app(SettingServiceInterface::class),
    ))->synchronise();
});

/** Register one definition into the test registry. */
function declare_setting(string $group, string $key, mixed ...$args): SettingDefinition
{
    $definition = new SettingDefinition(
        group: $group,
        key: $key,
        type: $args['type'] ?? SettingType::STRING,
        default: $args['default'] ?? null,
        isSecret: $args['isSecret'] ?? false,
        isPublic: $args['isPublic'] ?? false,
        isLocalized: $args['isLocalized'] ?? false,
        deprecatedSince: $args['deprecatedSince'] ?? null,
    );

    test()->registry->register($definition);

    return $definition;
}

/** A row written directly, as an earlier deployment would have left it. */
function existingRow(string $group, string $key, ?string $value, mixed ...$args): void
{
    DB::table('settings')->insert([
        'id' => (string) Str::ulid(),
        'group' => $group,
        'key' => $key,
        'value' => $value,
        'type' => ($args['type'] ?? SettingType::STRING)->value,
        'is_secret' => $args['isSecret'] ?? false,
        'is_public' => $args['isPublic'] ?? false,
        'is_localized' => $args['isLocalized'] ?? false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ── Materialising definitions ────────────────────────────────────────────────

test('a definition with no row is created carrying its default', function (): void {
    declare_setting('general', 'site_name', default: 'Default Name');

    $report = ($this->sync)();

    expect($report->createdReferences())->toBe(['general.site_name'])
        ->and(setting('general.site_name'))->toBe('Default Name');
});

test('a secret is created unset rather than with a default', function (): void {
    // A default would put credential material in the codebase; provisioning a secret
    // is an operator action (ADR 0018).
    declare_setting('security', 'api_key', isSecret: true);

    ($this->sync)();

    $row = Setting::query()->where('group', 'security')->where('key', 'api_key')->firstOrFail();

    expect($row->is_secret)->toBeTrue()
        ->and($row->value)->toBeNull();
});

test('synchronising twice changes nothing the second time', function (): void {
    // This is what makes it safe to run on every deploy rather than a thing someone
    // remembers to run.
    declare_setting('general', 'site_name', default: 'Default Name');

    $first = ($this->sync)();
    $second = ($this->sync)();

    expect($first->createdReferences())->toHaveCount(1)
        ->and($first->changedAnything())->toBeTrue()
        ->and($second->createdReferences())->toBe([])
        ->and($second->updatedReferences())->toBe([])
        ->and($second->unchangedReferences())->toBe(['general.site_name'])
        ->and($second->changedAnything())->toBeFalse();
});

// ── A configured value is never touched ──────────────────────────────────────

test('an operator value survives synchronisation', function (): void {
    declare_setting('general', 'site_name', default: 'Default Name');
    ($this->sync)();

    app(SettingServiceInterface::class)->set('general', 'site_name', 'Operator Chosen');

    ($this->sync)();

    expect(setting('general.site_name'))->toBe('Operator Chosen');
});

test('a changed default does not overwrite a configured value', function (): void {
    // The most dangerous case: shipping a new default must not revert what an
    // operator deliberately set.
    existingRow('general', 'site_name', 'Operator Chosen');
    declare_setting('general', 'site_name', default: 'A Brand New Default');

    ($this->sync)();

    expect(setting('general.site_name'))->toBe('Operator Chosen');
});

test('declared attributes are brought up to date without touching the value', function (): void {
    existingRow('general', 'site_name', 'Operator Chosen', isPublic: false);
    declare_setting('general', 'site_name', default: 'ignored', isPublic: true, isLocalized: true);

    $report = ($this->sync)();

    $row = Setting::query()->where('key', 'site_name')->firstOrFail();

    expect($report->updatedReferences())->toBe(['general.site_name'])
        ->and($row->is_public)->toBeTrue()
        ->and($row->is_localized)->toBeTrue()
        ->and($row->getRawValue())->toBe('Operator Chosen');
});

// ── Orphans are reported, never deleted ──────────────────────────────────────

test('a row whose definition disappeared is reported and kept', function (): void {
    // A definition can vanish from a bad merge or a branch deployed out of order.
    existingRow('general', 'retired', 'configured value');

    $report = ($this->sync)();

    expect($report->orphanedReferences())->toBe(['general.retired'])
        ->and($report->needsAttention())->toBeTrue()
        ->and(Setting::query()->where('key', 'retired')->exists())->toBeTrue()
        ->and(setting('general.retired'))->toBe('configured value');
});

test('a configured secret is never destroyed by a vanished definition', function (): void {
    // The case with no recovery: a deleted credential cannot be reconstructed from
    // anything the platform still holds.
    $secret = new Setting(['group' => 'security', 'key' => 'gone_key', 'type' => SettingType::STRING, 'is_secret' => true]);
    $secret->setRawValue('super-secret-token');
    $secret->save();

    $report = ($this->sync)();

    expect($report->orphanedReferences())->toBe(['security.gone_key'])
        ->and(Setting::query()->where('key', 'gone_key')->firstOrFail()->getRawValue())
        ->toBe('super-secret-token');
});

test('a deprecated definition orphans its row rather than deleting it', function (): void {
    existingRow('general', 'retired', 'configured value');
    declare_setting('general', 'retired', deprecatedSince: '2026-09-06');

    $report = ($this->sync)();

    expect($report->orphanedReferences())->toBe(['general.retired'])
        ->and(Setting::query()->where('key', 'retired')->exists())->toBeTrue();
});

test('the orphan report carries keys and never values', function (): void {
    $secret = new Setting(['group' => 'security', 'key' => 'gone_key', 'type' => SettingType::STRING, 'is_secret' => true]);
    $secret->setRawValue('super-secret-token');
    $secret->save();

    $encoded = json_encode(($this->sync)()->toArray());

    expect($encoded)->not->toContain('super-secret-token')
        ->and($encoded)->not->toContain($secret->refresh()->value)
        ->and($encoded)->toContain('security.gone_key');
});

// ── Refusals, where applying the declaration would corrupt the value ─────────

test('a row holding a value refuses to become secret', function (): void {
    // Its plaintext would then be read as ciphertext and raise. The synchroniser
    // cannot re-encrypt without knowing the value was meant as plaintext, so it says
    // so instead of guessing.
    existingRow('security', 'token', 'plain-text-value', isSecret: false);
    declare_setting('security', 'token', isSecret: true);

    $report = ($this->sync)();

    expect(array_keys($report->conflictReferences()))->toBe(['security.token'])
        ->and($report->conflictReferences()['security.token'])->toContain('clear it before')
        ->and(Setting::query()->where('key', 'token')->firstOrFail()->is_secret)->toBeFalse()
        ->and($report->needsAttention())->toBeTrue();
});

test('a row holding a value refuses a type change that would invalidate it', function (): void {
    existingRow('general', 'count', 'not-a-number');
    declare_setting('general', 'count', type: SettingType::INTEGER);

    $report = ($this->sync)();

    expect(array_keys($report->conflictReferences()))->toBe(['general.count'])
        ->and(Setting::query()->where('key', 'count')->firstOrFail()->type)->toBe(SettingType::STRING);
});

test('a type change the stored value survives is applied', function (): void {
    // Checked rather than assumed: "8" is valid as both a string and an integer.
    existingRow('general', 'count', '8');
    declare_setting('general', 'count', type: SettingType::INTEGER);

    $report = ($this->sync)();

    expect($report->updatedReferences())->toBe(['general.count'])
        ->and($report->conflictReferences())->toBe([])
        ->and(setting('general.count'))->toBe(8);
});

test('an unset row accepts any declaration change', function (): void {
    existingRow('security', 'token', null, isSecret: false);
    declare_setting('security', 'token', isSecret: true);

    $report = ($this->sync)();

    expect($report->conflictReferences())->toBe([])
        ->and(Setting::query()->where('key', 'token')->firstOrFail()->is_secret)->toBeTrue();
});

// ── The platform's own catalogue ─────────────────────────────────────────────

test('the real registry synchronises cleanly and is idempotent', function (): void {
    // Against the application's own catalogue rather than a test one: after seeding,
    // a synchronisation must report nothing to do and nothing to worry about.
    $this->seed(SettingSeeder::class);

    $report = app(SettingSynchronizer::class)->synchronise();

    expect($report->createdReferences())->toBe([])
        ->and($report->updatedReferences())->toBe([])
        ->and($report->orphanedReferences())->toBe([])
        ->and($report->conflictReferences())->toBe([])
        ->and($report->unchangedReferences())->not->toBeEmpty();
});

test('the seeder provisions the whole catalogue and declares none of it', function (): void {
    $this->seed(SettingSeeder::class);

    $declared = array_keys(app(SettingRegistry::class)->active());
    $rows = Setting::query()->get()
        ->map(static fn (Setting $s): string => $s->group.'.'.$s->key)
        ->sort()->values()->all();

    expect($rows)->toBe($declared);

    // The seeder is a delegate now: it must not contain a definition of its own.
    $source = (string) file_get_contents(
        app_path('Modules/Settings/Database/Seeders/SettingSeeder.php')
    );

    expect($source)->not->toContain('SettingType::')
        ->and($source)->not->toContain('is_secret')
        ->and($source)->toContain('SettingSynchronizer');
});
