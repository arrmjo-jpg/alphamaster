<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Authorization\Models\Permission;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

// ── Every catalogued permission has a label ──────────────────────────────────

test('every permission in the catalogue resolves a label in both locales', function (): void {
    $cases = AdminPermission::cases();

    // 16 through Phase 15; Phase 16A added the security, secrets and audit trio,
    // Phase 16B-2 added rollback, 16B-4 audit archival and 16B-5 configuration backup.
    expect($cases)->toHaveCount(22);

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($cases as $case) {
            $permission = Permission::query()->where('name', $case->value)->firstOrFail();
            $label = $permission->displayLabel();

            expect($label)->not->toBe($case->value, $case->value.' fell back to its identifier in '.$locale)
                ->and($label)->not->toStartWith('permission.', $case->value.' leaked its key in '.$locale);
        }
    }
});

test('labels read correctly in English', function (): void {
    app()->setLocale('en');

    expect(Permission::query()->where('name', 'users.update')->firstOrFail()->displayLabel())->toBe('Update users')
        ->and(Permission::query()->where('name', 'media.delete')->firstOrFail()->displayLabel())->toBe('Delete media');
});

test('labels read correctly in Arabic', function (): void {
    app()->setLocale('ar');

    expect(Permission::query()->where('name', 'users.update')->firstOrFail()->displayLabel())->toBe('تعديل المستخدمين')
        ->and(Permission::query()->where('name', 'media.delete')->firstOrFail()->displayLabel())->toBe('حذف الوسائط');
});

test('the identifier never changes with the locale', function (): void {
    $permission = Permission::query()->where('name', 'settings.update')->firstOrFail();

    app()->setLocale('en');
    $english = $permission->displayLabel();

    app()->setLocale('ar');
    $arabic = $permission->displayLabel();

    expect($permission->name)->toBe('settings.update')
        ->and($arabic)->not->toBe($english);
});

// ── The fallback ─────────────────────────────────────────────────────────────

test('a permission with no translation is humanised rather than blank', function (): void {
    // ADR 0030 asks for visibly wrong over empty. An administrator choosing
    // permissions needs something readable even where a key has been missed.
    app()->setLocale('ar');

    $uncatalogued = new Permission(['name' => 'widgets.archive', 'guard_name' => 'web', 'module' => 'widgets']);

    expect($uncatalogued->displayLabel())->toBe('Archive widgets')
        ->and($uncatalogued->displayLabel())->not->toBe('')
        ->and($uncatalogued->displayLabel())->not->toStartWith('permission.');
});

test('humanising handles multi-segment and underscored identifiers', function (): void {
    expect(Permission::humanize('users.view'))->toBe('View users')
        ->and(Permission::humanize('media.force_delete'))->toBe('Force delete media')
        ->and(Permission::humanize('reports.monthly.export'))->toBe('Export reports monthly');
});

// ── The catalogue contract ───────────────────────────────────────────────────

test('the catalogue keeps its module grouping and gains labels', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'perm-labels@example.test')['token'];

    resetClient($this);
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/v1/admin/permissions');

    $response->assertOk();

    $data = $response->json('data');

    expect($data)->toBeArray()
        // Grouped by the owning module (ADR 0014), not by the permission's first
        // segment: `users.update` is owned by the `user` module.
        ->and(array_keys($data))->toContain('user', 'authorization', 'media')
        ->and($data['user'])->toBeArray();

    foreach ($data as $module => $entries) {
        foreach ($entries as $entry) {
            expect(array_keys($entry))->toBe(['key', 'label'], $module.' entry is not {key, label}')
                ->and($entry['key'])->toBeString()
                ->and($entry['label'])->not->toBe($entry['key']);
        }
    }
});

test('the catalogue still lists every permission it listed before', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'perm-complete@example.test')['token'];

    resetClient($this);
    $data = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/admin/permissions')->assertOk()->json('data');

    $names = [];

    foreach ($data as $entries) {
        foreach ($entries as $entry) {
            $names[] = $entry['key'];
        }
    }

    sort($names);
    $expected = AdminPermission::values();
    sort($expected);

    expect($names)->toBe($expected);
});

test('the catalogue labels follow X-Locale', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'perm-locale@example.test')['token'];

    $labels = [];

    foreach (['en', 'ar'] as $locale) {
        resetClient($this);

        $data = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Locale' => $locale])
            ->getJson('/api/v1/admin/permissions')->assertOk()->json('data');

        $entry = collect($data['user'])->firstWhere('key', 'users.update');

        expect($entry)->not->toBeNull();
        $labels[$locale] = $entry['label'];
    }

    expect($labels['en'])->toBe('Update users')
        ->and($labels['ar'])->toBe('تعديل المستخدمين');
});

// ── Both catalogues agree ────────────────────────────────────────────────────

test('every permission key exists in both dictionaries and differs between them', function (): void {
    /** @var array<string, string> $en */
    $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true);
    /** @var array<string, string> $ar */
    $ar = json_decode((string) file_get_contents(base_path('lang/ar.json')), true);

    $keys = array_values(array_filter(array_keys($en), fn (string $k): bool => str_starts_with($k, 'permission.')));

    expect($keys)->toHaveCount(22);

    foreach ($keys as $key) {
        expect($ar)->toHaveKey($key)
            ->and($ar[$key])->not->toBe($en[$key], $key.' is untranslated');
    }
});

test('a key exists for every enum case, and no key is orphaned', function (): void {
    /** @var array<string, string> $en */
    $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true);

    $keyed = array_map(
        static fn (string $k): string => substr($k, strlen('permission.')),
        array_values(array_filter(array_keys($en), fn (string $k): bool => str_starts_with($k, 'permission.')))
    );

    $cases = AdminPermission::values();
    sort($keyed);
    sort($cases);

    expect($keyed)->toBe($cases);
});
