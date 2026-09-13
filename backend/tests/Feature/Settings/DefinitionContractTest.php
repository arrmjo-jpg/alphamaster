<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Definitions\SettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| What the definitions endpoint promises a form generator
|--------------------------------------------------------------------------
|
| The registry is meant to be the single source for rendering a settings form.
| Two things it was not telling anyone: the rules a value must satisfy — which
| became enforced in Phase 16B-6, so a form that does not know them submits what
| the API refuses — and the permission actually required, which for a secret is
| resolved rather than declared.
|
*/

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->registry = app(SettingRegistry::class);
});

/** The definitions catalogue, flattened by reference. */
function definitions(mixed $test): array
{
    resetClient($test);

    $response = $test->withToken(adminToken(roles: ['administrator']))
        ->getJson('/api/v1/admin/settings/definitions');

    $response->assertOk();

    $flat = [];

    foreach ($response->json('data') as $group) {
        foreach ($group as $definition) {
            $flat[$definition['key']] = $definition;
        }
    }

    return $flat;
}

// ── The rules travel ─────────────────────────────────────────────────────────

test('a declaration publishes the rules it will be judged by', function (): void {
    $published = definitions($this);

    // Enforced since Phase 16B-6. A client generating a form from this catalogue and
    // not knowing them will submit values the API refuses, which an operator reads as
    // the platform being broken rather than as a value being wrong.
    expect($published['branding.watermark_opacity']['rules'])->toBe(['integer', 'between:1,100'])
        ->and($published['general.official_email']['rules'])->toBe(['email:rfc', 'max:255']);
});

test('every declaration publishes its rules, whatever they are', function (): void {
    $published = definitions($this);
    $missing = [];

    foreach ($this->registry->all() as $reference => $definition) {
        /** @var SettingDefinition $definition */
        if (! array_key_exists($reference, $published)) {
            $missing[] = $reference.' (absent from the endpoint)';

            continue;
        }

        if (($published[$reference]['rules'] ?? null) !== $definition->rules) {
            $missing[] = $reference.' (rules do not match the declaration)';
        }
    }

    // Walks the whole registry, so a catalogue added later is covered when it is
    // declared rather than when somebody remembers to extend this test.
    expect($missing)->toBe([]);
});

test('a declaration with no rules publishes an empty list, not null', function (): void {
    $published = definitions($this);

    $unruled = collect($this->registry->all())
        ->first(fn (SettingDefinition $d): bool => $d->rules === []);

    expect($unruled)->not->toBeNull()
        // An absent key and an empty list mean different things to a form generator,
        // and only one of them is true here.
        ->and($published[$unruled->reference()]['rules'])->toBe([]);
});

// ── The permission is the effective one ──────────────────────────────────────

test('a secret publishes the permission that actually guards it', function (): void {
    $published = definitions($this);

    // The raw declaration carries null for a secret; the API enforces
    // settings.secrets.manage. Publishing the raw field showed every credential as
    // freely editable.
    expect($this->registry->get('mail.password')->permission)->toBeNull()
        ->and($published['mail.password']['permission'])->toBe('settings.secrets.manage')
        ->and($published['security.api_secret_key']['permission'])->toBe('settings.secrets.manage');
});

test('a setting with its own declared permission still publishes it', function (): void {
    $published = definitions($this);

    expect($published['operations.audit_retention_days']['permission'])->toBe('settings.security.update');
});

test('an ordinary setting publishes no extra permission', function (): void {
    $published = definitions($this);

    // Null means "settings.update is enough", which is different from "some permission
    // exists and we did not say which".
    expect($published['general.site_name']['permission'])->toBeNull();
});

test('every published permission is the one the API would enforce', function (): void {
    $published = definitions($this);
    $wrong = [];

    foreach ($this->registry->all() as $reference => $definition) {
        /** @var SettingDefinition $definition */
        $expected = $definition->requiredPermission();

        // array_key_exists, not ??: a null permission is the common and correct case,
        // and a coalescing default cannot tell it apart from a field that is missing.
        if (! array_key_exists($reference, $published)
            || ! array_key_exists('permission', $published[$reference])
            || $published[$reference]['permission'] !== $expected) {
            $wrong[] = $reference;
        }
    }

    // The whole registry again: a credential added to a future catalogue is covered
    // when it is declared, not when somebody remembers it.
    expect($wrong)->toBe([]);
});

test('every published permission exists in the catalogue', function (): void {
    $published = definitions($this);
    $known = AdminPermission::values();
    $unknown = [];

    foreach ($published as $reference => $definition) {
        $permission = $definition['permission'] ?? null;

        if ($permission !== null && ! in_array($permission, $known, true)) {
            $unknown[] = $reference.' => '.$permission;
        }
    }

    // A published permission nobody can hold would make a setting permanently
    // uneditable through an interface that believed it.
    expect($unknown)->toBe([]);
});

// ── Nothing else moved ───────────────────────────────────────────────────────

test('a secret still publishes no default and no value', function (): void {
    $published = definitions($this);

    // A definition describes a setting; what it is set to is a different endpoint with
    // a different shape. For a credential that separation is the protection.
    expect($published['mail.password']['default'])->toBeNull()
        ->and($published['mail.password'])->not->toHaveKey('value');
});

test('the definition payload keeps every field it had', function (): void {
    $published = definitions($this);

    expect(array_keys($published['general.site_name']))
        ->toEqualCanonicalizing([
            'key', 'group', 'name', 'label', 'help', 'type', 'type_label', 'nullable',
            'editable', 'is_secret', 'is_public', 'is_localized', 'default',
            'depends_on', 'rules', 'permission', 'deprecated',
            // Added 2026-09-10: who reads this setting, and the sentence saying so.
            // An addition is as much a contract change as a removal, which is what
            // this assertion is for.
            'reach', 'reach_notice',
        ]);
});

test('reading the catalogue still requires settings.view', function (): void {
    resetClient($this);

    $this->withToken(tokenWithPermissions(['audit.view']))
        ->getJson('/api/v1/admin/settings/definitions')
        ->assertStatus(403);
});
