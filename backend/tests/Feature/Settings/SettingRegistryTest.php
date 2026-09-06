<?php

declare(strict_types=1);

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Enums\SettingType;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** A definition with sensible defaults, overridable per test. */
function definition(string $group = 'general', string $key = 'example', mixed ...$overrides): SettingDefinition
{
    return new SettingDefinition(
        group: $group,
        key: $key,
        type: $overrides['type'] ?? SettingType::STRING,
        default: $overrides['default'] ?? null,
        nullable: $overrides['nullable'] ?? true,
        rules: $overrides['rules'] ?? [],
        isSecret: $overrides['isSecret'] ?? false,
        isPublic: $overrides['isPublic'] ?? false,
        isLocalized: $overrides['isLocalized'] ?? false,
        editable: $overrides['editable'] ?? true,
        permission: $overrides['permission'] ?? null,
        dependsOn: $overrides['dependsOn'] ?? [],
        deprecatedSince: $overrides['deprecatedSince'] ?? null,
    );
}

// ── A definition refuses to describe an impossible setting ───────────────────

test('a definition derives its reference and label keys from itself', function (): void {
    // Derived rather than declared, so a label cannot be invented at a call site.
    $d = definition('general', 'site_name');

    expect($d->reference())->toBe('general.site_name')
        ->and($d->labelKey())->toBe('setting.general.site_name')
        ->and($d->helpKey())->toBe('setting.general.site_name.help');
});

test('a secret setting may not also be localized', function (): void {
    // A credential has no language, and a per-locale copy multiplies what must be
    // protected (ADR 0018).
    expect(fn () => definition(isSecret: true, isLocalized: true))
        ->toThrow(InvalidArgumentException::class, 'cannot be both secret and localized');
});

test('a secret setting may not also be public', function (): void {
    expect(fn () => definition(isSecret: true, isPublic: true))
        ->toThrow(InvalidArgumentException::class, 'cannot be both secret and public');
});

test('a localized setting must be a string', function (): void {
    expect(fn () => definition(type: SettingType::INTEGER, isLocalized: true))
        ->toThrow(InvalidArgumentException::class, 'must be of type string');
});

test('a secret setting may not declare a default', function (): void {
    // A default would put credential material in the codebase; secrets are
    // provisioned unset and supplied by an operator (ADR 0018).
    expect(fn () => definition(isSecret: true, default: 'hunter2'))
        ->toThrow(InvalidArgumentException::class, 'must not declare a default');
});

test('a non-nullable setting must declare a default', function (): void {
    expect(fn () => definition(nullable: false, default: null))
        ->toThrow(InvalidArgumentException::class, 'must declare a default');
});

test('group and key must be lowercase identifiers', function (): void {
    expect(fn () => definition('General', 'site_name'))
        ->toThrow(InvalidArgumentException::class, 'must be a lowercase identifier');

    expect(fn () => definition('general', 'Site Name'))
        ->toThrow(InvalidArgumentException::class, 'must be a lowercase identifier');
});

test('a dependency must be a group.key reference', function (): void {
    expect(fn () => definition(dependsOn: ['enabled']))
        ->toThrow(InvalidArgumentException::class, 'invalid dependency');
});

// ── The registry is a single source ──────────────────────────────────────────

test('a setting cannot be declared twice', function (): void {
    // Two catalogues disagreeing about one setting has no correct resolution, and
    // taking the last would make the winner depend on boot order.
    $registry = new SettingRegistry;
    $registry->register(definition('general', 'site_name'));

    expect(fn () => $registry->register(definition('general', 'site_name')))
        ->toThrow(InvalidArgumentException::class, 'already registered');
});

test('the registry answers what exists and what does not', function (): void {
    $registry = new SettingRegistry;
    $registry->register(definition('general', 'site_name'));

    expect($registry->has('general.site_name'))->toBeTrue()
        ->and($registry->has('general.nope'))->toBeFalse()
        ->and($registry->get('general.site_name')->key)->toBe('site_name')
        ->and($registry->find('general', 'site_name')->key)->toBe('site_name');
});

test('an undeclared setting raises the same error the admin API returns', function (): void {
    // A setting that is not declared cannot be created through the API either
    // (ADR 0018), so both paths answer with the same exception.
    $registry = new SettingRegistry;

    expect(fn () => $registry->get('general.never_declared'))
        ->toThrow(UnknownSettingKeyException::class);
});

test('definitions are grouped and ordered deterministically', function (): void {
    $registry = new SettingRegistry;
    $registry->register(definition('mail', 'host'));
    $registry->register(definition('general', 'site_name'));
    $registry->register(definition('general', 'contact_email'));

    expect(array_keys($registry->all()))
        ->toBe(['general.contact_email', 'general.site_name', 'mail.host'])
        ->and(array_keys($registry->forGroup('general')))->toBe(['contact_email', 'site_name'])
        ->and($registry->groups())->toBe(['general', 'mail']);
});

test('a catalogue registers every definition it declares', function (): void {
    $catalogue = new class implements SettingCatalogue
    {
        public function definitions(): array
        {
            return [definition('general', 'one'), definition('general', 'two')];
        }
    };

    $registry = new SettingRegistry;
    $registry->registerCatalogue($catalogue);

    expect($registry->all())->toHaveCount(2);
});

test('a deprecated definition is excluded from the active set but still known', function (): void {
    // A retired definition keeps its row and is reported as an orphan rather than
    // deleted (ADR 0018), so the registry must still be able to describe it.
    $registry = new SettingRegistry;
    $registry->register(definition('general', 'current'));
    $registry->register(definition('general', 'retired', deprecatedSince: '2026-09-06'));

    expect($registry->all())->toHaveCount(2)
        ->and($registry->active())->toHaveCount(1)
        ->and(array_keys($registry->active()))->toBe(['general.current'])
        ->and($registry->get('general.retired')->isDeprecated())->toBeTrue();
});

// ── The new types are real, at the engine level ──────────────────────────────

test('the database accepts every declared setting type and no others', function (): void {
    // The constraint is engine-level because it must hold for a raw query-builder
    // write (ADR 0018). This asserts the migration actually widened it, rather than
    // trusting that it ran.
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The type constraint is asserted on the authoritative engine (ADR 0027).');
    }

    foreach (SettingType::values() as $type) {
        $accepted = true;

        try {
            DB::table('settings')->insert([
                'id' => (string) Str::ulid(),
                'group' => 'probe',
                'key' => 'k_'.$type,
                'value' => null,
                'type' => $type,
                'is_secret' => false,
                'is_public' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            $accepted = false;
        }

        expect($accepted)->toBeTrue($type.' was refused by the type constraint');
    }

    $bogusRefused = false;

    try {
        DB::table('settings')->insert([
            'id' => (string) Str::ulid(),
            'group' => 'probe',
            'key' => 'bogus',
            'value' => null,
            'type' => 'not_a_type',
            'is_secret' => false,
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (Throwable) {
        $bogusRefused = true;
    }

    expect($bogusRefused)->toBeTrue('an unknown type was accepted');
});

test('the new types are string backed in both directions', function (): void {
    foreach ([SettingType::URL, SettingType::EMAIL, SettingType::MEDIA] as $type) {
        expect($type->isStringBacked())->toBeTrue()
            ->and(Setting::serializeValue('value', $type))->toBe('value')
            ->and(Setting::castValue('value', $type))->toBe('value');
    }

    expect(SettingType::INTEGER->isStringBacked())->toBeFalse()
        ->and(SettingType::JSON->isStringBacked())->toBeFalse();
});
