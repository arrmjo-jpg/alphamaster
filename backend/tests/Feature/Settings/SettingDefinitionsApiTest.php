<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Enums\SettingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->service = app(SettingServiceInterface::class);
});

/** Every definition row, flattened out of its groups. */
function definitionRows(mixed $test, ?string $token = null): array
{
    $token ??= adminToken(roles: ['administrator']);

    $data = $test->withToken($token)
        ->getJson('/api/v1/admin/settings/definitions')
        ->assertOk()
        ->json('data');

    $rows = [];

    foreach ($data as $group) {
        foreach ($group as $row) {
            $rows[] = $row;
        }
    }

    return $rows;
}

// ── The catalogue endpoint ───────────────────────────────────────────────────

test('the definitions endpoint describes every declared setting', function (): void {
    $declared = app(SettingRegistry::class)->all();

    $keys = array_column(definitionRows($this), 'key');
    sort($keys);

    $expected = array_keys($declared);
    sort($expected);

    expect($keys)->toBe($expected)
        ->and($keys)->not->toBeEmpty();
});

test('a definition row carries the metadata an interface cannot infer', function (): void {
    $rows = collect(definitionRows($this))->keyBy('key');

    expect(array_keys($rows['general.site_name']))->toBe([
        'key', 'group', 'name', 'label', 'help', 'type', 'type_label',
        'nullable', 'editable', 'is_secret', 'is_public', 'is_localized',
        // Added 2026-09-10: who reads this setting, and the sentence saying so.
        // An interface cannot infer it — that is the whole reason it is published.
        'reach', 'reach_notice',
        'default', 'depends_on', 'rules', 'permission', 'deprecated',
    ]);
});

test('the technical members keep their names and gain labels beside them', function (): void {
    // ADR 0031: a label sits beside its identifier, never instead of it.
    foreach (definitionRows($this) as $row) {
        expect($row['type'])->toBeString()
            ->and($row['type_label'])->toBeString()
            ->and($row['type_label'])->not->toBe($row['type']);
    }
});

test('a label falls back to a humanised key rather than to blank', function (): void {
    // Translating is incremental; an interface has something readable the moment a
    // setting is declared and before anybody has written its label.
    //
    // Asserted against a definition that is deliberately not in the catalogue. Every
    // declared setting has a label now, so borrowing a real one — which this test used
    // to do with `general.site_name` — would assert the translation rather than the
    // fallback, and would pass whether or not the fallback still worked.
    $undeclared = new SettingDefinition(
        group: 'general',
        key: 'a_setting_nobody_has_labelled',
        type: SettingType::STRING,
    );

    expect($undeclared->label())->toBe('A Setting Nobody Has Labelled')
        ->and($undeclared->label())->not->toStartWith('setting.')
        ->and($undeclared->help())->toBeNull();

    // And the real one now reads as words rather than as its key.
    $rows = collect(definitionRows($this))->keyBy('key');

    expect($rows['general.site_name']['label'])->toBe('Site name')
        ->and($rows['general.site_name']['help'])->not->toBeNull();
});

test('the definitions carry declared defaults but never configured values', function (): void {
    // A definition describes a setting; what it is *set to* is a different endpoint.
    // The declared default is part of the description and legitimately appears; what
    // an operator has since configured must not.
    $this->service->set('security', 'api_secret_key', 'a-real-credential');
    $this->service->set('general', 'site_url', 'https://configured.example.test');

    $encoded = json_encode(definitionRows($this));

    expect($encoded)->not->toContain('a-real-credential')
        ->and($encoded)->not->toContain('https://configured.example.test')
        // ...while the declared default is present, because it is a declaration.
        ->and($encoded)->toContain('AlphaMaster Enterprise');
});

test('a secret never carries a default', function (): void {
    foreach (definitionRows($this) as $row) {
        if ($row['is_secret']) {
            expect($row['default'])->toBeNull($row['key'].' exposed a default');
        }
    }
});

test('the endpoint declares which settings need more than settings.update', function (): void {
    $rows = collect(definitionRows($this))->keyBy('key');

    expect($rows['operations.audit_retention_days']['permission'])->toBe('settings.security.update')
        ->and($rows['general.site_name']['permission'])->toBeNull()
        // A secret declares no permission of its own and is nonetheless guarded by
        // one. The endpoint publishes what the API enforces rather than what the
        // catalogue literally says, or an interface would show every credential as
        // freely editable.
        ->and($rows['mail.password']['permission'])->toBe('settings.secrets.manage');
});

test('dependencies are declared so an interface can say what is missing', function (): void {
    $rows = collect(definitionRows($this))->keyBy('key');

    expect($rows['branding.watermark_enabled']['depends_on'])->toBe(['branding.watermark_image'])
        ->and($rows['mail.enabled']['depends_on'])->toBe(['mail.host', 'mail.from_address']);
});

test('reading the catalogue requires settings.view', function (): void {
    $this->withToken(adminToken(roles: ['support']))
        ->getJson('/api/v1/admin/settings/definitions')
        ->assertStatus(403);

    // The token persists on the test client, so it is cleared before asserting the
    // unauthenticated case — otherwise this would assert nothing.
    resetClient($this);

    $this->getJson('/api/v1/admin/settings/definitions')->assertStatus(401);
});

test('definitions is not swallowed by the group route', function (): void {
    // The group pattern matches "definitions"; declaring the route first is what
    // stops this answering 404 for a group nobody named.
    $this->withToken(adminToken(roles: ['administrator']))
        ->getJson('/api/v1/admin/settings/definitions')
        ->assertOk()
        ->assertJsonPath('success', true);
});

// ── Sensitive settings need more than settings.update ───────────────────────

test('an administrator cannot change a secret without the secrets permission', function (): void {
    // administrator holds settings.update; credentials are deliberately out of reach.
    $token = adminToken(roles: ['administrator']);

    $this->withToken($token)
        ->withHeaders(['If-Match' => settingsVersion('security')])
        ->putJson('/api/v1/admin/settings/security', ['settings' => ['api_secret_key' => 'stolen']])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');

    expect($this->service->get('security.api_secret_key'))->toBeNull();
});

test('an administrator cannot change a setting guarded by its own permission', function (): void {
    $token = adminToken(roles: ['administrator']);
    $before = setting('operations.audit_retention_days');

    $this->withToken($token)
        ->withHeaders(['If-Match' => settingsVersion('operations')])
        ->putJson('/api/v1/admin/settings/operations', ['settings' => ['audit_retention_days' => 30]])
        ->assertStatus(403);

    expect(setting('operations.audit_retention_days'))->toBe($before);
});

test('a holder of the right permission may change it', function (): void {
    $token = adminToken(roles: ['super_admin']);

    $this->withToken($token)
        ->withHeaders(['If-Match' => settingsVersion('operations')])
        ->putJson('/api/v1/admin/settings/operations', ['settings' => ['audit_retention_days' => 400]])
        ->assertOk();

    expect(setting('operations.audit_retention_days'))->toBe(400);
});

test('an ordinary setting in the same group is still writable', function (): void {
    // The check is per key, not per group: sensitivity is a property of the setting.
    $this->withToken(adminToken(roles: ['administrator']))
        ->withHeaders(['If-Match' => settingsVersion('operations')])
        ->putJson('/api/v1/admin/settings/operations', ['settings' => ['provider_timeout_seconds' => 30]])
        ->assertOk();

    expect(setting('operations.provider_timeout_seconds'))->toBe(30);
});

test('a batch mixing a permitted and a forbidden key applies neither', function (): void {
    $before = setting('operations.provider_timeout_seconds');

    $this->withToken(adminToken(roles: ['administrator']))
        ->withHeaders(['If-Match' => settingsVersion('operations')])
        ->putJson('/api/v1/admin/settings/operations', ['settings' => [
            'provider_timeout_seconds' => 45,
            'audit_retention_days' => 30,
        ]])
        ->assertStatus(403);

    // Refused before anything was written, so the permitted half did not slip through.
    expect(setting('operations.provider_timeout_seconds'))->toBe($before);
});

test('every secret in the catalogue is guarded, whatever group it is in', function (): void {
    // Declared once on the definition rather than per route, so a credential added to
    // a future catalogue is covered when it is declared, not when someone remembers.
    foreach (app(SettingRegistry::class)->all() as $definition) {
        if ($definition->isSecret) {
            expect($definition->requiredPermission())
                ->toBe('settings.secrets.manage', $definition->reference().' is unguarded');
        }
    }
});
