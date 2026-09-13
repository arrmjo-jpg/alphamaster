<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * Role definitions in the audit trail (ADR 0037).
 *
 * Assigning a role to an account was audited from the start; editing the role itself —
 * the same grant, applied to everybody holding it at once — was not. These tests pin
 * what each write records, and what it deliberately leaves out: a label's wording,
 * which the role carries and nobody audits.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    $this->token = tokenWithPermissions([
        AdminPermission::ROLES_VIEW->value,
        AdminPermission::ROLES_UPDATE->value,
    ]);

    // Seeding writes records of its own; every assertion is about what the role
    // endpoints wrote afterwards.
    AuditRecord::query()->getQuery()->delete();
});

/**
 * The role records written so far, oldest first.
 *
 * @return list<AuditRecord>
 */
function roleTrail(): array
{
    return array_values(AuditRecord::query()
        ->where('action', 'like', 'role.%')
        ->orderBy('id')
        ->get()
        ->all());
}

/**
 * A role made through the endpoint, with the trail cleared behind it so the test reads
 * only what its own operation wrote.
 *
 * @param  array<int, string>  $permissions
 */
function roleForAudit(mixed $test, array $permissions, string $label = 'Content Editors'): Role
{
    $id = $test->withToken($test->token)
        ->postJson('/api/v1/admin/roles', ['label' => $label, 'permissions' => $permissions])
        ->assertCreated()
        ->json('data.id');

    AuditRecord::query()->getQuery()->delete();

    return Role::query()->findOrFail($id);
}

test('creating a role records its identifier and grant, and not its label', function (): void {
    $response = $this->withToken($this->token)->postJson('/api/v1/admin/roles', [
        'label' => 'Content Editors',
        'permissions' => ['users.view', 'audit.view'],
    ])->assertCreated();

    $trail = roleTrail();

    expect($trail)->toHaveCount(1)
        ->and($trail[0]->action)->toBe(AuditAction::ROLE_CREATED)
        ->and($trail[0]->subject)->toBe((string) $response->json('data.id'))
        ->and($trail[0]->context)->toBe([
            'role' => $response->json('data.name'),
            'permissions' => ['audit.view', 'users.view'],
        ])
        ->and(json_encode($trail[0]->context))->not->toContain('Content Editors');
});

test('changing what a role grants records what was added and what was removed', function (): void {
    $role = roleForAudit($this, ['users.view', 'audit.view']);

    $this->withToken($this->token)->putJson("/api/v1/admin/roles/{$role->id}", [
        'label' => 'Content Editors',
        'permissions' => ['users.view', 'media.view'],
    ])->assertOk();

    $trail = roleTrail();

    // One record: the label was re-sent unchanged, so it is not a second one.
    expect($trail)->toHaveCount(1)
        ->and($trail[0]->action)->toBe(AuditAction::ROLE_PERMISSIONS_CHANGED)
        ->and($trail[0]->subject)->toBe((string) $role->id)
        ->and($trail[0]->context)->toBe([
            'role' => $role->name,
            'added' => ['media.view'],
            'removed' => ['audit.view'],
        ]);
});

test('renaming a role records which language moved, and neither wording', function (): void {
    $role = roleForAudit($this, ['users.view']);

    $this->withToken($this->token)->putJson("/api/v1/admin/roles/{$role->id}", [
        'label' => 'Site Editors',
        'permissions' => ['users.view'],
    ])->assertOk();

    $trail = roleTrail();

    expect($trail)->toHaveCount(1)
        ->and($trail[0]->action)->toBe(AuditAction::ROLE_UPDATED)
        ->and($trail[0]->context)->toBe(['role' => $role->name, 'locale' => 'en']);

    $recorded = json_encode($trail[0]->context);

    expect($recorded)->not->toContain('Site Editors')
        ->and($recorded)->not->toContain('Content Editors');
});

test('saving a role unchanged records nothing', function (): void {
    $role = roleForAudit($this, ['users.view', 'audit.view']);

    // The editor sends the whole role back on every save, in any order.
    $this->withToken($this->token)->putJson("/api/v1/admin/roles/{$role->id}", [
        'label' => 'Content Editors',
        'permissions' => ['audit.view', 'users.view'],
    ])->assertOk();

    expect(roleTrail())->toBe([]);
});

test('deleting a role keeps what it granted and how many accounts held it', function (): void {
    $role = roleForAudit($this, ['users.view', 'audit.view']);

    foreach (['first', 'second'] as $which) {
        makeAccount([
            'name' => 'Holder '.$which,
            'email' => "holder-{$which}@example.com",
            'account_type' => AccountType::ADMIN,
        ])->assignRole($role);
    }

    $this->withToken($this->token)->deleteJson("/api/v1/admin/roles/{$role->id}")->assertOk();

    $trail = roleTrail();

    expect(Role::query()->find($role->id))->toBeNull()
        ->and($trail)->toHaveCount(1)
        ->and($trail[0]->action)->toBe(AuditAction::ROLE_DELETED)
        ->and($trail[0]->subject)->toBe((string) $role->id)
        ->and($trail[0]->context)->toBe([
            'role' => $role->name,
            'permissions' => ['audit.view', 'users.view'],
            'accounts_affected' => 2,
        ]);
});

test('a role write the operator may not make records nothing', function (): void {
    $readOnly = tokenWithPermissions([AdminPermission::ROLES_VIEW->value]);

    $this->withToken($readOnly)->postJson('/api/v1/admin/roles', [
        'label' => 'Refused Role',
        'permissions' => ['users.view'],
    ])->assertForbidden();

    expect(roleTrail())->toBe([]);
});

test('every role action resolves a label in both locales', function (): void {
    $actions = [
        AuditAction::ROLE_CREATED,
        AuditAction::ROLE_UPDATED,
        AuditAction::ROLE_PERMISSIONS_CHANGED,
        AuditAction::ROLE_DELETED,
    ];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($actions as $action) {
            expect(__('audit.action.'.$action))
                ->not->toBe('audit.action.'.$action, $action.' has no '.$locale.' label');
        }
    }
});
