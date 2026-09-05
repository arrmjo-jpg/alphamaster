<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Services\RoleIdentifier;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    $this->identifiers = app(RoleIdentifier::class);
});

// ── Normalisation ────────────────────────────────────────────────────────────

test('a label becomes an identifier in the existing grammar', function (): void {
    $cases = [
        'Content Editor' => 'content_editor',
        'content-editor' => 'content_editor',
        '  Spaced   Out  ' => 'spaced_out',
        'Content  Editor!' => 'content_editor',
        'UPPER CASE' => 'upper_case',
        'A' => 'a',
    ];

    foreach ($cases as $label => $expected) {
        expect($this->identifiers->fromLabel($label))->toBe($expected, $label);
    }
});

test('accented characters are transliterated rather than stripped', function (): void {
    // Str::ascii is the transliteration this framework already provides.
    expect($this->identifiers->fromLabel('Rédacteur en Chef'))->toBe('redacteur_en_chef');
});

test('every generated identifier matches the grammar RoleRequest already used', function (): void {
    foreach (['Content Editor', 'Rédacteur', 'Team-Lead 2', 'x'] as $label) {
        $identifier = $this->identifiers->fromLabel($label);

        expect($identifier)->not->toBeNull($label)
            ->and(preg_match(RoleIdentifier::PATTERN, (string) $identifier))->toBe(1, $label);
    }
});

test('a leading digit is dropped so the first character is a letter', function (): void {
    expect($this->identifiers->fromLabel('2024 Editors'))->toBe('editors');
});

test('a label that cannot produce an identifier returns null rather than inventing one', function (): void {
    foreach (['!!!', '   ', '---', '123', ''] as $label) {
        expect($this->identifiers->fromLabel($label))->toBeNull(var_export($label, true));
    }
});

test('normalisation is deterministic', function (): void {
    $first = $this->identifiers->fromLabel('Content Editor');
    $second = $this->identifiers->fromLabel('Content Editor');

    expect($first)->toBe($second);
});

// ── Collisions ───────────────────────────────────────────────────────────────

test('a taken identifier gains a deterministic numeric suffix', function (): void {
    expect($this->identifiers->generateUnique('Content Editor'))->toBe('content_editor');

    Role::query()->create(['name' => 'content_editor', 'guard_name' => 'web']);
    expect($this->identifiers->generateUnique('Content Editor'))->toBe('content_editor_2');

    Role::query()->create(['name' => 'content_editor_2', 'guard_name' => 'web']);
    expect($this->identifiers->generateUnique('Content Editor'))->toBe('content_editor_3');
});

test('a collision never reuses or overwrites an existing role', function (): void {
    $original = Role::query()->create(['name' => 'content_editor', 'guard_name' => 'web']);

    $next = $this->identifiers->generateUnique('Content Editor');

    expect($next)->not->toBe($original->name)
        ->and(Role::query()->where('name', $original->name)->count())->toBe(1);
});

test('a suffixed identifier still matches the grammar', function (): void {
    Role::query()->create(['name' => 'content_editor', 'guard_name' => 'web']);

    $suffixed = (string) $this->identifiers->generateUnique('Content Editor');

    expect(preg_match(RoleIdentifier::PATTERN, $suffixed))->toBe(1);
});

// ── Immutability ─────────────────────────────────────────────────────────────

test('a role identifier cannot be changed once the role exists', function (): void {
    // Enforced on the model, so no service, command or future controller can
    // rename a role that permissions and assignments reference by name.
    $role = Role::query()->create(['name' => 'content_editor', 'guard_name' => 'web']);

    expect(fn () => $role->update(['name' => 'something_else']))
        ->toThrow(RuntimeException::class);

    expect(Role::query()->where('name', 'content_editor')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'something_else')->exists())->toBeFalse();
});

test('saving a role without touching its name is unaffected', function (): void {
    $role = Role::query()->create(['name' => 'content_editor', 'guard_name' => 'web']);

    $role->syncPermissions(['users.view']);
    $role->touch();

    expect($role->refresh()->name)->toBe('content_editor')
        ->and($role->permissions->pluck('name')->all())->toBe(['users.view']);
});

// ── Over the API ─────────────────────────────────────────────────────────────

test('creating a role derives the identifier from the label', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'role-create@example.test')['token'];

    resetClient($this);
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/v1/admin/roles', [
            'name' => 'Content Editor',
            'permissions' => ['users.view'],
        ]);

    $response->assertStatus(201);

    expect($response->json('data.name'))->toBe('content_editor')
        ->and($response->json('data.name_label'))->toBe('Content Editor')
        ->and($response->json('data.permissions'))->toBe(['users.view']);
});

test('a client cannot choose the identifier independently of the label', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'role-choose@example.test')['token'];

    resetClient($this);
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/v1/admin/roles', [
            'name' => 'Totally Different Label',
            'permissions' => [],
        ]);

    $response->assertStatus(201);

    // The identifier follows the label; there is no separate field to set it.
    expect($response->json('data.name'))->toBe('totally_different_label');
});

test('updating a role changes the label and never the identifier', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'role-update@example.test')['token'];

    resetClient($this);
    $created = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/v1/admin/roles', ['name' => 'Content Editor', 'permissions' => []])
        ->assertStatus(201)->json('data');

    resetClient($this);
    $updated = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->putJson('/api/v1/admin/roles/'.$created['id'], ['name' => 'Content Lead', 'permissions' => ['users.view']])
        ->assertOk()->json('data');

    expect($updated['name'])->toBe($created['name'])
        ->and($updated['name'])->toBe('content_editor')
        ->and($updated['name_label'])->toBe('Content Lead')
        ->and($updated['permissions'])->toBe(['users.view']);
});

test('two roles created from the same label are distinct', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'role-collide@example.test')['token'];

    $identifiers = [];

    foreach ([1, 2] as $i) {
        resetClient($this);
        $identifiers[] = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/admin/roles', ['name' => 'Content Editor', 'permissions' => []])
            ->assertStatus(201)->json('data.name');
    }

    expect($identifiers)->toBe(['content_editor', 'content_editor_2']);
});

test('a label that yields no identifier is refused through the validation contract', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'role-unusable@example.test')['token'];

    resetClient($this);
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/v1/admin/roles', ['name' => '!!!', 'permissions' => []]);

    $response->assertStatus(422);

    expect($response->json('error.code'))->toBe('VALIDATION_ERROR')
        ->and($response->json('error.details.name.0'))->toBeString()
        ->and($response->json('error.details.name.0'))->not->toStartWith('validation.');
});

test('the refusal is localized like every other validation message', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'role-unusable-ar@example.test')['token'];

    resetClient($this);
    $arabic = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Locale' => 'ar'])
        ->postJson('/api/v1/admin/roles', ['name' => '!!!', 'permissions' => []])
        ->assertStatus(422)->json('error.details.name.0');

    resetClient($this);
    $english = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Locale' => 'en'])
        ->postJson('/api/v1/admin/roles', ['name' => '!!!', 'permissions' => []])
        ->assertStatus(422)->json('error.details.name.0');

    expect($arabic)->not->toBe($english)
        ->and($arabic)->toContain('معرّف');
});

test('a human label with spaces and capitals is accepted where it once was refused', function (): void {
    // The old grammar rejected anything but ^[a-z][a-z0-9_]*$, which is why
    // ADR 0029 recorded that an administrator had to type the identifier by hand.
    $token = adminWithRoles($this, ['super_admin'], 'role-human@example.test')['token'];

    resetClient($this);
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/v1/admin/roles', ['name' => 'Regional Support Lead', 'permissions' => []])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'regional_support_lead')
        ->assertJsonPath('data.name_label', 'Regional Support Lead');
});
