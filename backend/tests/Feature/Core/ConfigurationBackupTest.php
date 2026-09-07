<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Backup\ConfigurationExporter;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Models\Language;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** The credential used throughout, so one grep proves it never reaches a file. */
const SMTP_CREDENTIAL = 'smtp-credential-Q7x2Zt';

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('exports');
    config(['backup.configuration.disk' => 'exports', 'backup.configuration.path' => 'configuration-exports']);

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->settings = app(SettingServiceInterface::class);
});

/** Export through the API. */
function exportConfiguration(mixed $test, bool $includeSecrets = false, ?string $token = null): mixed
{
    $token ??= adminToken(roles: ['super_admin']);

    resetClient($test);

    return $test->withToken($token)
        ->postJson('/api/v1/admin/configuration/export', ['include_secrets' => $includeSecrets]);
}

/** Restore through the API. */
function restoreConfiguration(mixed $test, string $location, ?string $token = null): mixed
{
    $token ??= adminToken(roles: ['super_admin']);

    resetClient($test);

    return $test->withToken($token)
        ->postJson('/api/v1/admin/configuration/restore', ['location' => $location]);
}

/** The written export, decoded. */
function exportDocument(string $location): array
{
    return json_decode((string) Storage::disk('exports')->get($location), true);
}

/** Rewrite a field in a written export, to simulate one from elsewhere. */
function rewriteExport(string $location, callable $mutate): void
{
    $document = exportDocument($location);
    Storage::disk('exports')->put($location, (string) json_encode($mutate($document)));
}

// ── What an export contains ──────────────────────────────────────────────────

test('an export carries settings, languages and provider metadata', function (): void {
    $response = exportConfiguration($this);

    $response->assertOk();

    $document = exportDocument($response->json('data.location'));

    expect($document['shape_version'])->toBe(ConfigurationExporter::SHAPE_VERSION)
        ->and($document['sections'])->toHaveKeys(['localization', 'settings', 'integration'])
        ->and($document['sections']['settings']['rows'])->not->toBeEmpty()
        ->and($document['sections']['localization']['languages'])->not->toBeEmpty()
        ->and($document['key_fingerprint'])->toBeString();
});

test('an export never contains a decrypted secret', function (): void {
    $this->settings->set('mail', 'password', SMTP_CREDENTIAL);

    $response = exportConfiguration($this, includeSecrets: true);
    $response->assertOk();

    $raw = (string) Storage::disk('exports')->get($response->json('data.location'));

    // The ciphertext travels; the plaintext does not exist anywhere in the file. A file
    // containing plaintext credentials is a worse artefact than no backup at all,
    // because it will be copied to a laptop and attached to a ticket.
    expect($raw)->not->toContain(SMTP_CREDENTIAL)
        ->and($raw)->toContain('eyJpdiI6');
});

test('omitting secrets leaves them out and names them by key', function (): void {
    $this->settings->set('mail', 'password', SMTP_CREDENTIAL);

    $response = exportConfiguration($this, includeSecrets: false);

    $response->assertOk();

    $location = $response->json('data.location');
    $raw = (string) Storage::disk('exports')->get($location);
    $document = exportDocument($location);

    expect($document['omitted_secrets'])->toContain('mail.password')
        ->and($raw)->not->toContain(SMTP_CREDENTIAL)
        // Not the value in any form: no ciphertext either, once omission is chosen.
        ->and($raw)->not->toContain('eyJpdiI6');

    expect($document['sections']['settings']['rows'])
        ->not->toContain(fn (array $row): bool => $row['group'] === 'mail' && $row['key'] === 'password');
});

test('an export carries no audit records, no MFA and no identity', function (): void {
    $this->settings->set('mail', 'host', 'smtp.example.test');

    $response = exportConfiguration($this, includeSecrets: true);
    $response->assertOk();

    $document = exportDocument($response->json('data.location'));

    // Structural, not filtered: no contributor is registered for any of these stores,
    // so there is no path that could reach them (ADR 0039).
    expect(array_keys($document['sections']))->toBe(['localization', 'settings', 'integration'])
        ->and($document['sections'])->not->toHaveKey('audit')
        ->and($document['sections'])->not->toHaveKey('users')
        ->and($document['sections'])->not->toHaveKey('mfa_methods');
});

test('an export is audited', function (): void {
    AuditRecord::query()->getQuery()->delete();

    exportConfiguration($this)->assertOk();

    expect(AuditRecord::query()->where('action', AuditAction::CONFIGURATION_EXPORTED)->count())->toBe(1);
});

// ── Restoring ────────────────────────────────────────────────────────────────

test('a restore puts back the values the export carried', function (): void {
    $this->settings->set('general', 'site_name', 'Exported Name');

    $location = exportConfiguration($this)->json('data.location');

    $this->settings->set('general', 'site_name', 'Changed Afterwards');
    Cache::flush();

    restoreConfiguration($this, $location)->assertOk();

    expect(app(SettingServiceInterface::class)->get('general.site_name'))->toBe('Exported Name');
});

test('a restore creates the languages the export named, before the values that need them', function (): void {
    $location = exportConfiguration($this)->json('data.location');

    rewriteExport($location, function (array $document): array {
        $document['sections']['localization']['languages'][] = [
            'code' => 'fr', 'name' => 'French', 'native_name' => 'Français',
            'direction' => 'ltr', 'is_active' => true, 'is_default' => false, 'sort_order' => 9,
        ];

        $document['sections']['settings']['translations'][] = [
            'group' => 'general', 'key' => 'site_name', 'locale' => 'fr', 'value' => 'Nom du site',
        ];

        return $document;
    });

    restoreConfiguration($this, $location)->assertOk();

    $setting = Setting::query()->where('group', 'general')->where('key', 'site_name')->with('translations')->firstOrFail();

    // The translation landed, which it could not have if settings had been restored
    // before the language existed.
    expect(Language::query()->where('code', 'fr')->exists())->toBeTrue()
        ->and($setting->getTypedValue('fr'))->toBe('Nom du site');
});

test('a restore is audited once, with what it wrote and what it declined', function (): void {
    $location = exportConfiguration($this)->json('data.location');

    AuditRecord::query()->getQuery()->delete();

    restoreConfiguration($this, $location)->assertOk();

    $records = AuditRecord::query()->where('action', AuditAction::CONFIGURATION_RESTORED)->get();

    expect($records)->toHaveCount(1)
        ->and($records->first()?->context['sections'] ?? null)->toBeArray();
});

// ── The key fingerprint ──────────────────────────────────────────────────────

test('a restore under a different key declines every encrypted value and restores the rest', function (): void {
    $this->settings->set('mail', 'password', SMTP_CREDENTIAL);
    $this->settings->set('general', 'site_name', 'Exported Name');

    $location = exportConfiguration($this, includeSecrets: true)->json('data.location');

    // The same file, as it would look arriving from another deployment.
    rewriteExport($location, function (array $document): array {
        $document['key_fingerprint'] = str_repeat('0', 32);

        return $document;
    });

    $this->settings->set('general', 'site_name', 'Changed Afterwards');
    Cache::flush();

    $response = restoreConfiguration($this, $location);

    $response->assertOk()->assertJsonPath('data.encrypted_restorable', false);

    $skipped = collect($response->json('data.sections'))
        ->firstWhere('section', 'settings')['skipped'] ?? [];

    // The non-secret configuration is exactly what an operator seeding a new
    // environment wants; the credentials were never going to survive the trip, and
    // writing ciphertext that decrypts to nothing surfaces later as an integration that
    // stopped working for reasons nobody can trace (ADR 0039).
    expect(collect($skipped)->pluck('reason'))->toContain('key_mismatch')
        ->and(collect($skipped)->pluck('key'))->toContain('mail.password')
        ->and(app(SettingServiceInterface::class)->get('general.site_name'))->toBe('Exported Name')
        ->and(app(SettingServiceInterface::class)->get('mail.password'))->toBe(SMTP_CREDENTIAL);
});

test('an export with no fingerprint is treated as one written elsewhere', function (): void {
    $this->settings->set('mail', 'password', SMTP_CREDENTIAL);

    $location = exportConfiguration($this, includeSecrets: true)->json('data.location');

    rewriteExport($location, function (array $document): array {
        unset($document['key_fingerprint']);

        return $document;
    });

    // Nothing here can establish that the ciphertext is readable, and guessing is the
    // failure mode the fingerprint exists to prevent.
    restoreConfiguration($this, $location)
        ->assertOk()
        ->assertJsonPath('data.encrypted_restorable', false);
});

// ── Refusals ─────────────────────────────────────────────────────────────────

test('a restore refuses a location that does not exist', function (): void {
    restoreConfiguration($this, 'configuration-exports/nothing-here.json')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CONFIGURATION_RESTORE_REFUSED');
});

test('a restore refuses a file that is not an export', function (): void {
    Storage::disk('exports')->put('configuration-exports/junk.json', 'this is not json');

    restoreConfiguration($this, 'configuration-exports/junk.json')->assertStatus(422);
});

test('a restore refuses valid JSON that is not an export', function (): void {
    // Distinct from malformed JSON, which the decoder rejects. This is a readable
    // document of the wrong shape, and it has to be refused by something else.
    // Carrying a shape version this release understands, so the shape guard is not what
    // refuses it. What is missing is the thing an export is made of.
    Storage::disk('exports')->put('configuration-exports/wrong.json', '{"shape_version":1,"hello":"world"}');

    restoreConfiguration($this, 'configuration-exports/wrong.json')->assertStatus(422);
});

test('a restore refuses an export from a newer release', function (): void {
    $location = exportConfiguration($this)->json('data.location');

    rewriteExport($location, function (array $document): array {
        $document['shape_version'] = ConfigurationExporter::SHAPE_VERSION + 1;

        return $document;
    });

    // This release cannot know what a later one added, and a restore that quietly
    // ignores fields it does not recognise writes a configuration nobody asked for.
    restoreConfiguration($this, $location)->assertStatus(422);
});

test('a restore cannot climb out of the configured disk', function (): void {
    restoreConfiguration($this, '../../../.env')->assertStatus(422);
});

test('a value that no longer fits its declaration is skipped, never coerced', function (): void {
    $location = exportConfiguration($this)->json('data.location');

    rewriteExport($location, function (array $document): array {
        foreach ($document['sections']['settings']['rows'] as $index => $row) {
            if ($row['group'] === 'branding' && $row['key'] === 'watermark_opacity') {
                $document['sections']['settings']['rows'][$index]['value'] = 'not-an-integer';
            }
        }

        return $document;
    });

    $response = restoreConfiguration($this, $location);
    $response->assertOk();

    $skipped = collect($response->json('data.sections'))->firstWhere('section', 'settings')['skipped'] ?? [];

    expect(collect($skipped)->pluck('key'))->toContain('branding.watermark_opacity')
        // Reported and skipped. A coerced restore is a corruption nobody notices.
        ->and(Setting::query()->where('group', 'branding')->where('key', 'watermark_opacity')->value('value'))
        ->not->toBe('not-an-integer');
});

test('a section the export does not carry is left alone, not emptied', function (): void {
    $this->settings->set('general', 'site_name', 'Still Here');

    $location = exportConfiguration($this)->json('data.location');

    rewriteExport($location, function (array $document): array {
        unset($document['sections']['settings']);

        return $document;
    });

    $response = restoreConfiguration($this, $location);
    $response->assertOk();

    // Not reached at all, rather than handed an empty section: the settings contributor
    // synchronises definitions on the way in, so "skipped" and "given nothing" are
    // different operations with different side effects.
    expect(collect($response->json('data.sections'))->pluck('section'))->not->toContain('settings')
        // An export written before a module existed must not delete that module's
        // configuration on the way in.
        ->and(app(SettingServiceInterface::class)->get('general.site_name'))->toBe('Still Here');
});

// ── Permissions ──────────────────────────────────────────────────────────────

test('exporting and restoring are not settings.update', function (): void {
    $token = tokenWithPermissions(['settings.view', 'settings.update']);

    exportConfiguration($this, token: $token)->assertStatus(403);
    restoreConfiguration($this, 'configuration-exports/whatever.json', token: $token)->assertStatus(403);
});

test('the seeded administrator cannot export or restore configuration', function (): void {
    $token = adminToken(roles: ['administrator']);

    exportConfiguration($this, token: $token)->assertStatus(403);
    restoreConfiguration($this, 'configuration-exports/whatever.json', token: $token)->assertStatus(403);
});

test('the configuration endpoints are behind the admin perimeter', function (): void {
    $this->postJson('/api/v1/admin/configuration/export')->assertStatus(401);
    resetClient($this);
    $this->postJson('/api/v1/admin/configuration/restore', ['location' => 'x'])->assertStatus(401);
});
