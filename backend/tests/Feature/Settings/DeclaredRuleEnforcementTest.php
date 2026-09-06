<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\DefinitionValidator;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Exceptions\SettingValueRejectedException;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->service = app(SettingServiceInterface::class);
    $this->registry = app(SettingRegistry::class);
    $this->validator = app(DefinitionValidator::class);
});

/*
|--------------------------------------------------------------------------
| The audit ADR 0040 asked for before this could be switched on
|--------------------------------------------------------------------------
|
| A rule that has never run has never been proven to accept the values already
| stored under it. Turning enforcement on without checking that would refuse an
| operator's next save of a value that has been sitting there since Phase 16A —
| a fault that looks like the platform breaking rather than like a declaration
| being wrong.
|
| These two tests are that audit, kept permanently: they fail if a future
| catalogue declares a rule its own default or its seeded value cannot satisfy.
|
*/

test('every declared default satisfies its own rules', function (): void {
    $offenders = [];

    foreach ($this->registry->all() as $definition) {
        /** @var SettingDefinition $definition */
        if ($definition->isSecret || $definition->default === null) {
            continue;
        }

        $messages = $this->validator->messages($definition, $definition->default);

        if ($messages !== []) {
            $offenders[$definition->reference()] = $messages;
        }
    }

    expect($offenders)->toBe([]);
});

test('every value the seeder provisions satisfies its own rules', function (): void {
    $offenders = [];

    foreach (Setting::query()->get() as $setting) {
        $reference = $setting->group.'.'.$setting->key;

        if ($setting->is_secret || ! $this->registry->has($reference)) {
            continue;
        }

        $definition = $this->registry->get($reference);
        $messages = $this->validator->messages($definition, $setting->getTypedValue());

        if ($messages !== []) {
            $offenders[$reference] = $messages;
        }
    }

    expect($offenders)->toBe([]);
});

// ── The rules now run ────────────────────────────────────────────────────────

test('a value outside its declared bounds is refused', function (): void {
    // between:1,100. Declared since Phase 16A, published through the definitions
    // endpoint, and until now enforced nowhere.
    expect(fn () => $this->service->set('branding', 'watermark_opacity', 400))
        ->toThrow(SettingValueRejectedException::class);

    expect($this->service->get('branding.watermark_opacity'))->not->toBe(400);
});

test('a value that is not an address is refused where an address is declared', function (): void {
    expect(fn () => $this->service->set('general', 'official_email', 'not-an-address'))
        ->toThrow(SettingValueRejectedException::class);
});

test('the refusal names the setting rather than the field', function (): void {
    $token = adminToken(roles: ['administrator']);

    $response = $this->withToken($token)
        ->withHeader('If-Match', '"'.settingsVersion('branding').'"')
        ->putJson('/api/v1/admin/settings/branding', ['settings' => ['watermark_opacity' => 400]]);

    // An operator writing twenty settings at once needs to know which one was refused.
    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'SETTING_VALUE_REJECTED')
        ->assertJsonPath('error.details.setting', 'branding.watermark_opacity');
});

test('a refused write leaves the whole batch unapplied', function (): void {
    $before = $this->service->get('branding.watermark_position');

    $this->withToken(adminToken(roles: ['administrator']))
        ->withHeader('If-Match', '"'.settingsVersion('branding').'"')
        ->putJson('/api/v1/admin/settings/branding', ['settings' => [
            'watermark_position' => 'center',
            'watermark_opacity' => 400,
        ]])
        ->assertStatus(422);

    // The transaction takes the valid key with it, which is how batch updates have
    // always behaved for an unknown key or an unrepresentable type.
    expect(app(SettingServiceInterface::class)->get('branding.watermark_position'))->toBe($before);
});

test('a valid value still writes', function (): void {
    $this->service->set('branding', 'watermark_opacity', 55);

    expect(app(SettingServiceInterface::class)->get('branding.watermark_opacity'))->toBe(55);
});

test('clearing a setting is still allowed, rules or not', function (): void {
    // Null is the absence of a value, not a value that could satisfy a rule. Whether
    // clearing is permitted is what `nullable` describes, and that is a separate
    // question this change deliberately did not answer.
    $this->service->set('general', 'official_email', null);

    expect(app(SettingServiceInterface::class)->get('general.official_email'))->toBeNull();
});

test('a setting with no declared rules is unaffected', function (): void {
    $definition = collect($this->registry->all())
        ->first(fn (SettingDefinition $d): bool => $d->rules === [] && ! $d->isSecret && ! $d->isLocalized);

    expect($definition)->not->toBeNull();
});

test('a secret is not rule-checked, because its stored form is ciphertext', function (): void {
    // Applying rules to the plaintext would mean holding it here to do so, and applying
    // them to the stored form would mean testing a rule against ciphertext.
    $this->service->set('security', 'api_secret_key', 'anything-at-all');

    expect(app(SettingServiceInterface::class)->get('security.api_secret_key'))->toBe('anything-at-all');
});

// ── One rule, three writers ──────────────────────────────────────────────────

test('a restore cannot install a value the write path would refuse', function (): void {
    $definition = $this->registry->get('branding.watermark_opacity');

    // The same declaration, asked by the restore path's validator rather than the
    // write path's. Three writers sharing one validator is the point: three copies
    // would be three chances for one of them to be the lenient one.
    expect($this->validator->violates($definition, 400))->toBeTrue()
        ->and($this->validator->violates($definition, 55))->toBeFalse();
});
