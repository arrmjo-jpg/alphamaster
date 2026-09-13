<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

// Creating, editing and switching off an account.
//
// Three operations that did not exist, added because the permissions describing them
// already did. What is under test is mostly what they refuse: creation cannot mint an
// administrator, an edit cannot change standing, and nobody can switch themselves off.

test('creating an account makes a regular one, whatever else is asked for', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_CREATE->value]);

    $response = $this->withToken($token)->postJson('/api/v1/admin/users', [
        'name' => 'Rami Haddad',
        'email' => 'rami@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        // Neither is a field this endpoint accepts. They are sent anyway, because the
        // property worth asserting is that they are ignored rather than absent.
        'account_type' => 'admin',
        'roles' => ['super_admin'],
    ])->assertCreated();

    $created = User::query()->where('email', 'rami@example.test')->firstOrFail();

    expect($created->account_type)->toBe(AccountType::USER)
        ->and($created->getRoleNames()->all())->toBe([])
        // Promotion is the only route across the boundary, and it needs a different
        // permission from the one used here.
        ->and($response->json('data.account_type'))->toBe('user');
});

test('a created account is not verified, because nobody confirmed its address', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_CREATE->value]);

    $this->withToken($token)->postJson('/api/v1/admin/users', [
        'name' => 'Unverified Person',
        'email' => 'unverified-on-create@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertCreated()
        ->assertJsonPath('data.email_verified', false);

    expect(User::query()->where('email', 'unverified-on-create@example.test')->firstOrFail()->email_verified_at)
        ->toBeNull();
});

test('the password is hashed and is never published', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_CREATE->value]);

    $response = $this->withToken($token)->postJson('/api/v1/admin/users', [
        'name' => 'Hashed Person',
        'email' => 'hashed@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertCreated();

    $created = User::query()->where('email', 'hashed@example.test')->firstOrFail();

    expect($created->password)->not->toBe('a-long-enough-password')
        ->and(Hash::check('a-long-enough-password', $created->password))->toBeTrue()
        ->and($response->json('data'))->not->toHaveKey('password');
});

test('the password minimum is the platform setting, not a number fixed in code', function (): void {
    app(SettingServiceInterface::class)->set('auth', 'password_min_length', 20);
    Cache::flush();

    $token = tokenWithPermissions([AdminPermission::USERS_CREATE->value]);

    $this->withToken($token)->postJson('/api/v1/admin/users', [
        'name' => 'Short Password',
        'email' => 'short@example.test',
        'password' => 'only-sixteen-ch!',
        'password_confirmation' => 'only-sixteen-ch!',
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('creating needs users.create, and users.view is not enough', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_VIEW->value]);

    $this->withToken($token)->postJson('/api/v1/admin/users', [
        'name' => 'Refused',
        'email' => 'refused@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

test('a phone number is stored through the accessor that keeps its hash in step', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_CREATE->value]);

    $this->withToken($token)->postJson('/api/v1/admin/users', [
        'name' => 'Reachable Person',
        'email' => 'reachable@example.test',
        'phone' => '+962790000123',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertCreated();

    $created = User::query()->where('email', 'reachable@example.test')->firstOrFail();

    // The number is canonical and the lookup hash describes *this* number, which is
    // what makes the account findable at sign-in and what the unique constraint covers.
    expect($created->phone)->toBe('+962790000123')
        ->and($created->phone_hash)->not->toBeNull()
        ->and(User::findByPhone('+962790000123')?->id)->toBe($created->id);
});

test('a number the platform cannot read is refused beside the field, not at save time', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_CREATE->value]);

    // No country code. The platform refuses rather than guessing one, and it refuses
    // in validation: without the rule this reaches the accessor and raises a 500.
    $this->withToken($token)->postJson('/api/v1/admin/users', [
        'name' => 'Unreadable Number',
        'email' => 'unreadable@example.test',
        'phone' => '0790000123',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('separators an operator types are read rather than refused', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_CREATE->value]);

    $this->withToken($token)->postJson('/api/v1/admin/users', [
        'name' => 'Spaced Number',
        'email' => 'spaced@example.test',
        'phone' => '+962 79 000 0555',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertCreated();

    expect(User::query()->where('email', 'spaced@example.test')->firstOrFail()->phone)
        ->toBe('+962790000555');
});

test('an edit that changes the number moves the hash with it', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);
    $account = makeAccount(['email' => 'moving-number@example.test']);
    $account->phone = '+962790000123';
    $account->save();

    $this->withToken($token)->putJson('/api/v1/admin/users/'.$account->id, [
        'phone' => '+962790000999',
    ])->assertOk();

    // A hash left describing the previous number would leave the unique constraint
    // guarding a value the row no longer has.
    expect(User::findByPhone('+962790000123'))->toBeNull()
        ->and(User::findByPhone('+962790000999')?->id)->toBe($account->id);
});

test('an edit changes identity and cannot change standing', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);
    $account = makeAccount(['email' => 'before@example.test', 'name' => 'Before']);

    $this->withToken($token)->putJson('/api/v1/admin/users/'.$account->id, [
        'name' => 'After',
        // Sent and ignored: each has its own operation and its own permission.
        'account_type' => 'admin',
        'is_active' => false,
        'roles' => ['super_admin'],
    ])->assertOk()->assertJsonPath('data.name', 'After');

    $fresh = $account->fresh();

    expect($fresh->name)->toBe('After')
        ->and($fresh->account_type)->toBe(AccountType::USER)
        ->and($fresh->is_active)->toBeTrue();
});

test('changing the address clears its verification', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);
    $account = makeAccount(['email' => 'old-address@example.test']);

    expect($account->email_verified_at)->not->toBeNull();

    $this->withToken($token)->putJson('/api/v1/admin/users/'.$account->id, [
        'email' => 'new-address@example.test',
    ])->assertOk()->assertJsonPath('data.email_verified', false);

    // Otherwise an administrator could move an account onto an address nobody has
    // confirmed while the platform went on treating it as confirmed.
    expect($account->fresh()->email_verified_at)->toBeNull();
});

test('an edit that leaves the address alone leaves its verification alone', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);
    $account = makeAccount(['email' => 'unchanged@example.test']);

    $this->withToken($token)->putJson('/api/v1/admin/users/'.$account->id, [
        'name' => 'Renamed Only',
        'email' => 'unchanged@example.test',
    ])->assertOk()->assertJsonPath('data.email_verified', true);

    expect($account->fresh()->email_verified_at)->not->toBeNull();
});

test('deactivating stops sign-in and revokes what was already issued', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);
    $account = makeAccount(['email' => 'to-deactivate@example.test']);
    $account->createToken('their-session', ['user:access']);

    expect($account->tokens()->count())->toBe(1);

    $this->withToken($token)->postJson('/api/v1/admin/users/'.$account->id.'/deactivate')
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($account->fresh()->is_active)->toBeFalse()
        // A live token belonging to an account that has been switched off is a loose
        // end; promotion and demotion do not leave one either.
        ->and($account->tokens()->count())->toBe(0);
});

test('activating lets an account back in', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_UPDATE->value]);
    $account = makeAccount(['email' => 'to-activate@example.test', 'is_active' => false]);

    $this->withToken($token)->postJson('/api/v1/admin/users/'.$account->id.'/activate')
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    expect($account->fresh()->is_active)->toBeTrue();
});

test('an administrator cannot deactivate themselves', function (): void {
    $admin = makeAccount([
        'name' => 'Self Deactivator',
        'email' => 'self@example.test',
        'account_type' => AccountType::ADMIN,
    ]);
    $admin->givePermissionTo([AdminPermission::USERS_UPDATE->value]);
    $token = $admin->createToken('test-token', ['admin:access'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/admin/users/'.$admin->id.'/deactivate')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CANNOT_DEACTIVATE_SELF');

    // The platform would have accepted it and refused every request afterwards,
    // leaving somebody locked out of the console they were administering.
    expect($admin->fresh()->is_active)->toBeTrue();
});

test('activation needs users.update, and users.view is not enough', function (): void {
    $token = tokenWithPermissions([AdminPermission::USERS_VIEW->value]);
    $account = makeAccount(['email' => 'protected@example.test']);

    $this->withToken($token)->postJson('/api/v1/admin/users/'.$account->id.'/deactivate')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');

    expect($account->fresh()->is_active)->toBeTrue();
});
