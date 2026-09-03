<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Authorization\Exceptions\NotAnAdminAccountException;
use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Contracts\AccountTypeManagerContract;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->rbac = app(AdminRbacContract::class);
});

// ── 1. The users table supports exactly the intended account types ─────────────

test('the account type column accepts exactly admin and user', function (): void {
    expect(AccountType::values())->toBe(['admin', 'user']);

    foreach (AccountType::values() as $type) {
        $id = (string) Str::ulid();
        DB::table('users')->insert([
            'id' => $id, 'name' => 'T', 'email' => $type.'@example.com',
            'password' => 'x', 'account_type' => $type, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        expect(DB::table('users')->where('id', $id)->value('account_type'))->toBe($type);
    }
});

test('the database rejects any other account type, even on a raw write', function (): void {
    foreach (['superuser', 'root', 'ADMIN', ''] as $bogus) {
        $rejected = false;
        try {
            DB::transaction(fn () => DB::table('users')->insert([
                'id' => (string) Str::ulid(), 'name' => 'T', 'email' => 'bogus@example.com',
                'password' => 'x', 'account_type' => $bogus, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        } catch (QueryException) {
            $rejected = true;
        }

        expect($rejected)->toBeTrue("account_type [{$bogus}] should have been rejected");
    }
});

// ── 2. A regular user cannot receive admin:access ──────────────────────────────

test('a regular user never receives an admin:access token', function (): void {
    $regular = regularWithToken($this);

    $token = PersonalAccessToken::findToken($regular['token']);

    expect($token->abilities)->toBe([TokenAbility::USER_ACCESS->value])
        ->and($token->can(TokenAbility::ADMIN_ACCESS->value))->toBeFalse();
});

test('a regular user holding every admin role still receives only user:access', function (): void {
    $user = makeAccount([
        'email' => 'sneaky@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
        'account_type' => AccountType::USER,
    ]);

    // Force the relations in at the database level, bypassing the service boundary
    // entirely — the harshest version of "somehow has a Role relation".
    foreach (Role::query()->pluck('id') as $roleId) {
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId, 'model_type' => User::class, 'model_id' => $user->id,
        ]);
    }
    foreach (Permission::query()->pluck('id') as $permissionId) {
        DB::table('model_has_permissions')->insert([
            'permission_id' => $permissionId, 'model_type' => User::class, 'model_id' => $user->id,
        ]);
    }

    resetClient($this);
    $data = $this->postJson('/api/v1/auth/login', [
        'email' => 'sneaky@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data');

    expect($data['abilities'])->toBe([TokenAbility::USER_ACCESS->value]);

    // And the perimeter still refuses them.
    resetClient($this);
    $this->withToken($data['token'])->getJson('/api/v1/admin/users')->assertStatus(403);
});

// ── 3. A regular user cannot obtain admin RBAC permissions ────────────────────

test('admin permissions never resolve for a regular account', function (): void {
    $user = makeAccount([
        'email' => 'norbac@example.com', 'account_type' => AccountType::USER,
    ]);

    foreach (Permission::query()->pluck('id') as $permissionId) {
        DB::table('model_has_permissions')->insert([
            'permission_id' => $permissionId, 'model_type' => User::class, 'model_id' => $user->id,
        ]);
    }

    // Spatie itself would say yes; the boundary says no, and the boundary is what
    // every caller in the platform goes through.
    expect($this->rbac->allows($user->refresh(), AdminPermission::USERS_VIEW))->toBeFalse()
        ->and($this->rbac->permissionsFor($user))->toBe([])
        ->and($this->rbac->rolesFor($user))->toBe([])
        ->and($this->rbac->participates($user))->toBeFalse();
});

// ── 4. A regular user cannot assign themselves an admin role ──────────────────

test('assigning a role to a regular account is refused by the service', function (): void {
    $user = makeAccount(['email' => 'selfpromote@example.com', 'account_type' => AccountType::USER]);

    expect(fn () => $this->rbac->assignRole($user, 'super_admin'))
        ->toThrow(NotAnAdminAccountException::class);

    expect(fn () => $this->rbac->syncRoles($user, ['administrator']))
        ->toThrow(NotAnAdminAccountException::class);
});

test('a regular user cannot reach the role assignment endpoint at all', function (): void {
    $regular = regularWithToken($this);

    $this->withToken($regular['token'])
        ->putJson('/api/v1/admin/users/'.$regular['user']->id.'/roles', ['roles' => ['super_admin']])
        ->assertStatus(403);

    expect($this->rbac->rolesFor($regular['user']->refresh()))->toBe([]);
});

// ── 5 & 17. account_type cannot be changed through untrusted input ────────────

test('account_type is not mass assignable, in strict mode or in production mode', function (): void {
    $user = makeAccount(['email' => 'massassign@example.com', 'account_type' => AccountType::USER]);

    // Outside production the app runs Model::shouldBeStrict(), so an attempt to mass
    // assign a guarded attribute is refused loudly.
    expect(fn () => $user->fill(['name' => 'Renamed', 'account_type' => AccountType::ADMIN]))
        ->toThrow(MassAssignmentException::class);

    expect($user->refresh()->account_type)->toBe(AccountType::USER);

    // In production the same attempt discards the attribute instead of throwing. The
    // security property has to hold there too, so it is asserted rather than assumed.
    Model::preventSilentlyDiscardingAttributes(false);

    try {
        $created = User::create([
            'name' => 'Created', 'email' => 'created@example.com',
            'password' => 'x', 'account_type' => 'admin', 'is_active' => true,
        ]);

        expect($created->refresh()->account_type)->toBe(AccountType::USER);

        $user->fill(['name' => 'Renamed Again', 'account_type' => AccountType::ADMIN]);
        $user->save();

        expect($user->refresh()->account_type)->toBe(AccountType::USER)
            ->and($user->name)->toBe('Renamed Again');
    } finally {
        Model::preventSilentlyDiscardingAttributes();
    }
});

test('no public endpoint accepts an account_type change', function (): void {
    $regular = regularWithToken($this);

    // The authenticated self-service surface: nothing here takes account_type.
    $this->withToken($regular['token'])
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.account_type', 'user');

    // And submitting it anywhere reachable changes nothing.
    $this->withToken($regular['token'])->postJson('/api/v1/auth/logout', [
        'account_type' => 'admin',
    ])->assertOk();

    expect($regular['user']->refresh()->account_type)->toBe(AccountType::USER);
});

test('the role sync endpoint cannot smuggle an account type change', function (): void {
    $admin = adminWithRoles($this, ['super_admin']);
    $target = makeAccount(['email' => 'target@example.com', 'account_type' => AccountType::USER]);

    // The target is a regular account, so the role sync is refused outright — and the
    // smuggled account_type is ignored either way.
    $this->withToken($admin['token'])
        ->putJson('/api/v1/admin/users/'.$target->id.'/roles', [
            'roles' => [],
            'account_type' => 'admin',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'NOT_AN_ADMIN_ACCOUNT');

    expect($target->refresh()->account_type)->toBe(AccountType::USER);

    // And against a legitimate admin target, where the sync does succeed, the extra
    // field still changes nothing.
    $other = makeAccount(['email' => 'otheradmin@example.com', 'account_type' => AccountType::ADMIN]);

    $this->withToken($admin['token'])
        ->putJson('/api/v1/admin/users/'.$other->id.'/roles', [
            'roles' => ['support'],
            'account_type' => 'user',
        ])
        ->assertOk();

    expect($other->refresh()->account_type)->toBe(AccountType::ADMIN);
});

// ── 6 & 7. Permission gating for administrators ───────────────────────────────

test('an admin with admin:access but without users.view is refused', function (): void {
    // 'support' carries users.view, so use a role that does not: create one with none.
    $admin = adminWithRoles($this, [], 'nopermission@example.com');

    $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/users')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

test('an admin with admin:access and users.view receives the expected response', function (): void {
    $admin = adminWithRoles($this, ['support'], 'haspermission@example.com');

    $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/users')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('being an administrator does not by itself grant every permission', function (): void {
    $admin = adminWithRoles($this, ['support'], 'limited@example.com');

    // support has users.view but not roles.update.
    $this->withToken($admin['token'])->getJson('/api/v1/admin/users')->assertOk();

    $this->withToken($admin['token'])
        ->putJson('/api/v1/admin/users/'.$admin['user']->id.'/roles', ['roles' => []])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

// ── 8. Substitute for the deferred user-group test ────────────────────────────

test('no relation a regular account can hold yields admin access', function (): void {
    // User groups are deferred, so this asserts the stronger property they were meant
    // to illustrate: classification of any kind never becomes administrative capability.
    $user = makeAccount(['email' => 'related@example.com', 'password' => TEST_ACCOUNT_PASSWORD, 'account_type' => AccountType::USER]);

    foreach (Role::query()->pluck('id') as $roleId) {
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId, 'model_type' => User::class, 'model_id' => $user->id,
        ]);
    }

    expect($this->rbac->allows($user->refresh(), AdminPermission::USERS_VIEW))->toBeFalse();

    resetClient($this);
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'related@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.token');

    resetClient($this);
    $this->withToken($token)->getJson('/api/v1/admin/users')->assertStatus(403);
});

// ── 9 & 10. Roles grant permissions; removal removes access ───────────────────

test('an admin role grants exactly the permissions it carries', function (): void {
    $admin = adminWithRoles($this, ['editor'], 'editor@example.com');

    expect($this->rbac->allows($admin['user'], AdminPermission::USERS_VIEW))->toBeTrue()
        ->and($this->rbac->allows($admin['user'], AdminPermission::SETTINGS_VIEW))->toBeTrue()
        ->and($this->rbac->allows($admin['user'], AdminPermission::USERS_DELETE))->toBeFalse()
        ->and($this->rbac->allows($admin['user'], AdminPermission::ROLES_UPDATE))->toBeFalse();
});

test('removing the permission removes access', function (): void {
    $admin = adminWithRoles($this, ['support'], 'losing@example.com');

    $this->withToken($admin['token'])->getJson('/api/v1/admin/users')->assertOk();

    // Strip users.view from the role the administrator holds.
    Role::query()->where('name', 'support')->first()->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    resetClient($this);
    $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/users')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

// ── 11 & 12. Promotion and demotion ───────────────────────────────────────────

test('demoting an admin revokes their tokens and strips their roles', function (): void {
    $admin = adminWithRoles($this, ['super_admin'], 'demoteme@example.com');

    expect(PersonalAccessToken::findToken($admin['token']))->not->toBeNull();

    app(AccountTypeManagerContract::class)->demote($admin['user']);

    expect($admin['user']->refresh()->account_type)->toBe(AccountType::USER)
        ->and(PersonalAccessToken::query()->where('tokenable_id', $admin['user']->id)->count())->toBe(0)
        ->and($this->rbac->rolesFor($admin['user']))->toBe([])
        ->and(DB::table('model_has_roles')->where('model_id', $admin['user']->id)->count())->toBe(0);

    // The revoked token is dead.
    resetClient($this);
    $this->withToken($admin['token'])->getJson('/api/v1/admin/users')->assertStatus(401);
});

test('promotion happens only through the protected workflow', function (): void {
    $actor = adminWithRoles($this, ['super_admin'], 'promoter@example.com');
    $target = makeAccount(['email' => 'promoteme@example.com', 'password' => TEST_ACCOUNT_PASSWORD, 'account_type' => AccountType::USER]);

    $this->withToken($actor['token'])
        ->postJson('/api/v1/admin/users/'.$target->id.'/promote')
        ->assertOk()
        ->assertJsonPath('data.account_type', 'admin');

    expect($target->refresh()->account_type)->toBe(AccountType::ADMIN);

    // And the newly promoted admin must now enrol MFA before receiving admin:access.
    resetClient($this);
    $data = $this->postJson('/api/v1/auth/login', [
        'email' => 'promoteme@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data');

    expect($data['mfa_setup_required'])->toBeTrue()
        ->and($data)->not->toHaveKey('token');
});

test('an admin without users.update cannot promote anyone', function (): void {
    $weak = adminWithRoles($this, ['support'], 'weakadmin@example.com');
    $target = makeAccount(['email' => 'notpromoted@example.com', 'account_type' => AccountType::USER]);

    $this->withToken($weak['token'])
        ->postJson('/api/v1/admin/users/'.$target->id.'/promote')
        ->assertStatus(403);

    expect($target->refresh()->account_type)->toBe(AccountType::USER);
});

test('promotion revokes tokens issued while the account was a regular user', function (): void {
    $regular = regularWithToken($this, 'wasuser@example.com');

    app(AccountTypeManagerContract::class)->promote($regular['user']);

    expect(PersonalAccessToken::findToken($regular['token']))->toBeNull();
});

// ── 13, 14, 15. Phase 5 MFA rules still hold ──────────────────────────────────

test('admin login still requires mandatory MFA', function (): void {
    makeAccount([
        'email' => 'stillmfa@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
        'account_type' => AccountType::ADMIN,
    ]);

    resetClient($this);
    $data = $this->postJson('/api/v1/auth/login', [
        'email' => 'stillmfa@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data');

    expect($data['mfa_setup_required'])->toBeTrue()
        ->and($data['abilities'])->toBe([TokenAbility::MFA_ENROL->value])
        ->and($data)->not->toHaveKey('token');
});

test('an unenrolled admin holding every role still cannot reach the admin API', function (): void {
    $admin = makeAccount([
        'email' => 'unenrolled@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
        'account_type' => AccountType::ADMIN,
    ]);
    $this->rbac->syncRoles($admin, ['super_admin']);

    resetClient($this);
    $enrolmentToken = $this->postJson('/api/v1/auth/login', [
        'email' => 'unenrolled@example.com', 'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.enrolment_token');

    // Every permission in the catalogue, and still no way in.
    $this->withToken($enrolmentToken)->getJson('/api/v1/admin/users')->assertStatus(403);
    $this->withToken($enrolmentToken)->getJson('/api/v1/admin/roles')->assertStatus(403);
});

test('an enrolled admin receives admin:access only after satisfying MFA', function (): void {
    $admin = adminWithRoles($this, ['super_admin'], 'enrolled@example.com');

    expect(PersonalAccessToken::findToken($admin['token'])->abilities)
        ->toBe([TokenAbility::ADMIN_ACCESS->value]);

    $this->withToken($admin['token'])->getJson('/api/v1/admin/users')->assertOk();
});

// ── 16. Knowing permission keys buys nothing ──────────────────────────────────

test('a regular user cannot reach the admin API knowing valid permission keys', function (): void {
    $regular = regularWithToken($this, 'keyknower@example.com');

    foreach (AdminPermission::values() as $key) {
        $this->withToken($regular['token'])
            ->getJson('/api/v1/admin/users?permission='.$key)
            ->assertStatus(403);
    }

    $this->withToken($regular['token'])->getJson('/api/v1/admin/roles')->assertStatus(403);
    $this->withToken($regular['token'])->getJson('/api/v1/admin/permissions')->assertStatus(403);
});

// ── Catalogue integrity ───────────────────────────────────────────────────────

test('every catalogued permission is seeded with its owning module', function (): void {
    foreach (AdminPermission::cases() as $case) {
        $permission = Permission::query()->where('name', $case->value)->first();

        expect($permission)->not->toBeNull()
            ->and($permission->module)->toBe($case->module());
    }
});

test('the permission catalogue endpoint groups by module', function (): void {
    $admin = adminWithRoles($this, ['administrator'], 'cataloguer@example.com');

    $data = $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/permissions')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKeys(['authorization', 'settings', 'user'])
        ->and($data['user'])->toContain(AdminPermission::USERS_VIEW->value);
});

test('listing users resolves roles for every row without lazy loading', function (): void {
    // The suite previously exercised this endpoint with a single account, so an N+1
    // over the collection went unnoticed until a live request hit it. Several accounts
    // of both types, each with roles, make the eager load load-bearing: with
    // Model::shouldBeStrict() active a lazy load raises rather than merely being slow.
    $admin = adminWithRoles($this, ['super_admin'], 'lister@example.com');

    adminWithRoles($this, ['editor'], 'listed-admin-a@example.com');
    adminWithRoles($this, ['support'], 'listed-admin-b@example.com');
    makeAccount(['email' => 'listed-user-a@example.com', 'account_type' => AccountType::USER]);
    makeAccount(['email' => 'listed-user-b@example.com', 'account_type' => AccountType::USER]);

    resetClient($this);
    $data = $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/users')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(5);

    $byEmail = collect($data)->keyBy('email');

    // Administrators report their roles; regular accounts report none, whatever rows exist.
    expect($byEmail['listed-admin-a@example.com']['roles'])->toBe(['editor'])
        ->and($byEmail['listed-admin-b@example.com']['roles'])->toBe(['support'])
        ->and($byEmail['listed-user-a@example.com']['roles'])->toBe([])
        ->and($byEmail['listed-user-a@example.com']['permissions'])->toBe([])
        ->and($byEmail['listed-user-a@example.com']['account_type'])->toBe('user')
        ->and($byEmail['listed-admin-a@example.com']['account_type'])->toBe('admin');
});

test('showing, promoting and demoting a user each resolve relations safely', function (): void {
    $admin = adminWithRoles($this, ['super_admin'], 'mutator@example.com');
    $target = makeAccount(['email' => 'mutated@example.com', 'account_type' => AccountType::USER]);

    resetClient($this);
    $this->withToken($admin['token'])->getJson('/api/v1/admin/users/'.$target->id)->assertOk();
    $this->withToken($admin['token'])->postJson('/api/v1/admin/users/'.$target->id.'/promote')->assertOk();
    $this->withToken($admin['token'])->putJson('/api/v1/admin/users/'.$target->id.'/roles', ['roles' => ['support']])->assertOk();
    $this->withToken($admin['token'])->postJson('/api/v1/admin/users/'.$target->id.'/demote')->assertOk();

    expect($target->refresh()->account_type)->toBe(AccountType::USER);
});
