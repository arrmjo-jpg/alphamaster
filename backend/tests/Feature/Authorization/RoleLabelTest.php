<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Models\RoleTranslation;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

/** A role created at runtime, as an administrator would. */
function runtimeRole(string $name, array $labels = []): Role
{
    $role = Role::query()->create(['name' => $name, 'guard_name' => 'web']);

    foreach ($labels as $locale => $label) {
        $role->setTranslation($locale, ['label' => $label]);
    }

    return $role->refresh();
}

// ── The table follows the established convention ─────────────────────────────

test('role_translations follows the owner-translation pattern', function (): void {
    expect(Schema::hasTable('role_translations'))->toBeTrue()
        ->and(Schema::hasColumns('role_translations', ['id', 'role_id', 'locale', 'label', 'created_at', 'updated_at']))
        ->toBeTrue();
});

test('a role may hold only one label per locale', function (): void {
    $role = runtimeRole('content_editor', ['en' => 'Content Editor']);

    // setTranslation updates rather than duplicating, which is what the unique
    // index on (role_id, locale) guarantees.
    $role->setTranslation('en', ['label' => 'Content Lead']);

    expect(RoleTranslation::query()->where('role_id', $role->id)->where('locale', 'en')->count())->toBe(1)
        ->and($role->refresh()->translate('label'))->toBe('Content Lead');
});

test('deleting a role removes its translations', function (): void {
    $role = runtimeRole('temporary_role', ['en' => 'Temporary', 'ar' => 'مؤقت']);
    $id = $role->id;

    expect(RoleTranslation::query()->where('role_id', $id)->count())->toBe(2);

    $role->delete();

    expect(RoleTranslation::query()->where('role_id', $id)->count())->toBe(0);
});

// ── The three label sources ──────────────────────────────────────────────────

test('a runtime role reads its label relationally, per locale', function (): void {
    $role = runtimeRole('content_editor', ['en' => 'Content Editor', 'ar' => 'محرر المحتوى']);

    app()->setLocale('en');
    expect($role->displayLabel())->toBe('Content Editor');

    app()->setLocale('ar');
    expect($role->displayLabel())->toBe('محرر المحتوى');

    // The identifier is untouched by either.
    expect($role->name)->toBe('content_editor');
});

test('a built-in role reads its label from the catalogue', function (): void {
    // Built-in roles are defined by a deployment, so their labels are code-defined
    // and live in the language files (ADR 0030) rather than as translation rows.
    $role = Role::query()->where('name', 'super_admin')->firstOrFail();

    app()->setLocale('en');
    expect($role->displayLabel())->toBe('Super Administrator');

    app()->setLocale('ar');
    expect($role->displayLabel())->toBe('مدير عام');

    expect(RoleTranslation::query()->where('role_id', $role->id)->count())
        ->toBe(0, 'a built-in role should not need translation rows');
});

test('every built-in role has a catalogue label in both locales', function (): void {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach (Role::query()->get() as $role) {
            $label = $role->displayLabel();

            expect($label)->not->toBe('')
                ->and($label)->not->toStartWith('role.', $role->name.' leaked its key in '.$locale);
        }
    }
});

test('a role with neither translation nor catalogue entry is humanised', function (): void {
    $role = runtimeRole('no_label_role');

    expect($role->displayLabel())->toBe('No Label Role')
        ->and($role->displayLabel())->not->toBe('')
        ->and($role->displayLabel())->not->toStartWith('role.');
});

test('a missing locale falls back through the chain rather than going blank', function (): void {
    // Labelled in English only; an Arabic reader still gets something.
    $role = runtimeRole('english_only', ['en' => 'English Only']);

    app()->setLocale('ar');

    expect($role->displayLabel())->toBe('English Only');
});

// ── The API contract ─────────────────────────────────────────────────────────

test('the role resource keeps name and gains name_label', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'role-labels@example.test')['token'];

    resetClient($this);
    $data = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/admin/roles')->assertOk()->json('data');

    expect($data)->not->toBeEmpty();

    foreach ($data as $row) {
        expect(array_keys($row))->toBe(['id', 'name', 'name_label', 'permissions'])
            ->and($row['name'])->toBeString()
            ->and($row['name_label'])->toBeString()
            ->and($row['name_label'])->not->toBe('');
    }
});

test('the role labels follow X-Locale while the identifiers do not', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'role-locale@example.test')['token'];

    $seen = [];

    foreach (['en', 'ar'] as $locale) {
        resetClient($this);

        $data = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Locale' => $locale])
            ->getJson('/api/v1/admin/roles')->assertOk()->json('data');

        $row = collect($data)->firstWhere('name', 'super_admin');
        $seen[$locale] = $row;
    }

    expect($seen['en']['name'])->toBe($seen['ar']['name'])
        ->and($seen['en']['name_label'])->toBe('Super Administrator')
        ->and($seen['ar']['name_label'])->toBe('مدير عام');
});

test('every built-in role key exists in both dictionaries', function (): void {
    /** @var array<string, string> $en */
    $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true);
    /** @var array<string, string> $ar */
    $ar = json_decode((string) file_get_contents(base_path('lang/ar.json')), true);

    $keys = array_values(array_filter(array_keys($en), fn (string $k): bool => str_starts_with($k, 'role.')));

    expect($keys)->toHaveCount(4);

    foreach ($keys as $key) {
        expect($ar)->toHaveKey($key)
            ->and($ar[$key])->not->toBe($en[$key], $key.' is untranslated');
    }
});
