<?php

declare(strict_types=1);

use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * An account may have no password; an administrator never may (ADR 0050 §12).
 *
 * Asserted against the database, because the rule has to hold for the writes that never
 * reach a service, and against sign-in, because an account without a password must be
 * indistinguishable from one with the wrong password.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
});

/**
 * The database's refusal of a statement, or null when it was accepted.
 */
function passwordRuleRefusal(callable $statement): ?string
{
    try {
        DB::transaction(static fn () => $statement());

        return null;
    } catch (QueryException $e) {
        return $e->getMessage();
    }
}

test('a user account may exist without a password', function (): void {
    $user = makeAccount(['email' => 'no-password@example.test']);

    expect(passwordRuleRefusal(fn () => DB::table('users')->where('id', $user->id)->update(['password' => null])))->toBeNull()
        ->and(DB::table('users')->where('id', $user->id)->value('password'))->toBeNull();
});

test('an administrator cannot be left without a password', function (): void {
    $admin = makeAccount(['email' => 'admin-password@example.test', 'account_type' => AccountType::ADMIN]);

    expect(passwordRuleRefusal(fn () => DB::table('users')->where('id', $admin->id)->update(['password' => null])))
        ->toMatch('/chk_users_admin_has_password/')
        ->and(DB::table('users')->where('id', $admin->id)->value('password'))->not->toBeNull();
});

test('an account without a password cannot be made an administrator', function (): void {
    $user = makeAccount(['email' => 'passwordless-promotion@example.test']);
    DB::table('users')->where('id', $user->id)->update(['password' => null]);

    expect(passwordRuleRefusal(fn () => DB::table('users')->where('id', $user->id)->update(['account_type' => 'admin'])))
        ->toMatch('/chk_users_admin_has_password/')
        ->and(DB::table('users')->where('id', $user->id)->value('account_type'))->toBe('user');
});

test('an administrator cannot be created without a password', function (): void {
    $insert = fn () => DB::table('users')->insert([
        'id' => (string) Str::ulid(),
        'name' => 'Passwordless Administrator',
        'email' => 'passwordless-admin@example.test',
        'password' => null,
        'account_type' => 'admin',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(passwordRuleRefusal($insert))->toMatch('/chk_users_admin_has_password/')
        ->and(DB::table('users')->where('email', 'passwordless-admin@example.test')->exists())->toBeFalse();
});

test('the account type constraint still holds after the password column changed', function (): void {
    // On SQLite, making the column nullable rebuilds the table. The triggers guarding it
    // are carried across explicitly, and this is the one that proves it.
    $user = makeAccount(['email' => 'type-constraint@example.test']);

    expect(passwordRuleRefusal(fn () => DB::table('users')->where('id', $user->id)->update(['account_type' => 'superuser'])))
        ->toMatch('/chk_users_account_type_allowed/');
});

test('signing in to an account with no password fails exactly like a wrong password', function (): void {
    $socialOnly = makeAccount(['email' => 'social-only@example.test']);
    DB::table('users')->where('id', $socialOnly->id)->update(['password' => null]);

    makeAccount(['email' => 'has-password@example.test']);

    $noPassword = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'social-only@example.test',
        'password' => 'any-password-at-all',
    ]);

    resetClient($this);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'has-password@example.test',
        'password' => 'not-the-password',
    ]);

    // Identical in status, code, message and attempts remaining: nothing in the answer
    // says the first account signs in some other way.
    expect($noPassword->status())->toBe(401)
        ->and($wrongPassword->status())->toBe(401)
        ->and($noPassword->json('error'))->toBe($wrongPassword->json('error'))
        ->and($noPassword->json('error.code'))->toBe('INVALID_CREDENTIALS');
});
