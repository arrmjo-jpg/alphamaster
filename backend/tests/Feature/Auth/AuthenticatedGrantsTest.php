<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| What /auth/me tells a client it may do
|--------------------------------------------------------------------------
|
| An admin client decides what to render from this endpoint. Before it carried
| the account's grants, the only thing it could see was the token's abilities —
| and every administrator's token carries `admin:access` whether they may change
| a setting or merely look at one. The alternative strategy was to render
| everything and let the API answer 403.
|
*/

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);
});

/** Read the identity endpoint. */
function me(mixed $test, string $token): mixed
{
    resetClient($test);

    return $test->withToken($token)->getJson('/api/v1/auth/me');
}

test('an administrator is told the roles and permissions they hold', function (): void {
    $response = me($this, adminToken(roles: ['administrator']));

    $response->assertOk()
        ->assertJsonPath('data.roles', ['administrator']);

    $permissions = $response->json('data.permissions');

    expect($permissions)->toContain('settings.view')
        ->and($permissions)->toContain('settings.update')
        ->and($permissions)->toContain('settings.rollback')
        ->and($permissions)->toContain('audit.view');
});

test('the grants describe the account, not the token', function (): void {
    $response = me($this, adminToken(roles: ['administrator']));

    $permissions = $response->json('data.permissions');

    // The distinction the endpoint exists to make. `abilities` says what this
    // credential is for; `permissions` says what its holder is entitled to, and a
    // client cannot decide what to render from the first.
    $response->assertJsonPath('data.abilities', ['admin:access']);

    expect($permissions)->not->toContain('admin:access')
        ->and($permissions)->not->toContain('settings.secrets.manage')
        ->and($permissions)->not->toContain('audit.manage')
        ->and($permissions)->not->toContain('settings.backup.manage');
});

test('a super_admin is told it holds every permission in the catalogue', function (): void {
    $response = me($this, adminToken(roles: ['super_admin']));

    $permissions = $response->json('data.permissions');

    expect($permissions)->toContain('settings.secrets.manage')
        ->and($permissions)->toContain('audit.manage')
        ->and($permissions)->toContain('settings.backup.manage')
        ->and($permissions)->toHaveCount(count(AdminPermission::cases()));
});

test('permissions granted directly, without a role, are reported', function (): void {
    // The combination the seeded roles cannot express, and the one an operator with a
    // bespoke grant actually has.
    $response = me($this, tokenWithPermissions(['settings.view', 'audit.view']));

    $response->assertOk()->assertJsonPath('data.roles', []);

    expect($response->json('data.permissions'))->toEqualCanonicalizing(['settings.view', 'audit.view']);
});

test('an administrator with no role holds nothing', function (): void {
    $response = me($this, adminToken());

    $response->assertOk()
        ->assertJsonPath('data.roles', [])
        ->assertJsonPath('data.permissions', []);
});

// ── The boundary ─────────────────────────────────────────────────────────────

test('a regular account is reported as holding nothing at all', function (): void {
    // Only an administrator participates in the authorization system (ADR 0028).
    // Reporting anything else here would describe a grant nothing would honour, and
    // would hand a regular user the shape of the admin catalogue.
    $response = me($this, adminToken(['user:access'], isAdmin: false));

    $response->assertOk()
        ->assertJsonPath('data.account_type', 'user')
        ->assertJsonPath('data.roles', [])
        ->assertJsonPath('data.permissions', []);
});

test('a regular account holds nothing even where rows exist', function (): void {
    // The adversarial case: rows attached to an account that does not participate.
    // The boundary must answer from participation, not from the table.
    $user = User::query()->create([
        'name' => 'Regular With Rows',
        'email' => 'rows@example.com',
        'password' => bcrypt('secret'),
        'is_active' => true,
    ]);

    $user->givePermissionTo('settings.view');

    $token = $user->createToken('test-token', ['user:access'])->plainTextToken;

    $response = me($this, $token);

    $response->assertOk()
        ->assertJsonPath('data.roles', [])
        ->assertJsonPath('data.permissions', []);
});

test('the endpoint still requires a token', function (): void {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('an enrolment token cannot read the identity endpoint', function (): void {
    // mfa:enrol is not an access ability. An administrator mid-enrolment must not be
    // able to read what they will be entitled to before the second factor exists.
    $user = User::query()->create([
        'name' => 'Enrolling Admin',
        'email' => 'enrolling@example.com',
        'password' => bcrypt('secret'),
        'is_active' => true,
    ]);

    $token = $user->createToken('test-token', ['mfa:enrol'])->plainTextToken;

    me($this, $token)->assertStatus(403);
});

test('the payload carries exactly the declared fields and no others', function (): void {
    $response = me($this, adminToken(roles: ['administrator']));

    // A contract regression guard: the admin client is being built against this shape,
    // and a field quietly disappearing is the failure that shows up as a blank screen.
    //
    // The list grows only deliberately. It caught email_verified and
    // email_verified_at arriving, which is exactly what it is for — an addition is as
    // much a contract change as a removal, and this is where it gets acknowledged.
    expect(array_keys($response->json('data')))
        ->toEqualCanonicalizing([
            'id', 'name', 'email', 'account_type', 'is_active',
            'email_verified', 'email_verified_at',
            'phone', 'phone_verified', 'phone_verified_at',
            'abilities', 'roles', 'permissions',
        ]);
});
