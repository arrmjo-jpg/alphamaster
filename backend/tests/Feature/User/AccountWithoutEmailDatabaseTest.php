<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Storage invariants for accounts without an email address and for location
 * (ADR 0051 §2, §5), proven against raw statements rather than through the application.
 */

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function insertRawAccount(array $overrides = []): string
{
    $id = (string) Str::ulid();

    DB::table('users')->insert(array_merge([
        'id' => $id,
        'name' => 'Raw Account',
        'email' => null,
        'password' => null,
        'account_type' => 'user',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

function rawAccountRefusal(Closure $statement): ?string
{
    try {
        DB::transaction($statement);

        return null;
    } catch (QueryException $e) {
        return $e->getMessage();
    }
}

test('a user account may have no email address', function (): void {
    $id = insertRawAccount();

    expect(DB::table('users')->where('id', $id)->value('email'))->toBeNull();
});

test('an administrator must have an email address, whether inserted or updated', function (): void {
    expect(rawAccountRefusal(fn () => insertRawAccount(['account_type' => 'admin', 'password' => 'hash'])))
        ->toContain('chk_users_admin_has_email');

    $id = insertRawAccount(['email' => 'admin-to-be@example.test', 'password' => 'hash']);
    DB::table('users')->where('id', $id)->update(['account_type' => 'admin']);

    expect(rawAccountRefusal(fn () => DB::table('users')->where('id', $id)->update(['email' => null])))
        ->toContain('chk_users_admin_has_email');
});

test('coordinates come in pairs and in range, and a country code is two capitals', function (array $values): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The location constraints are asserted on the authoritative engine (ADR 0027).');
    }

    expect(rawAccountRefusal(fn () => insertRawAccount($values)))->not->toBeNull();
})->with([
    'latitude alone' => [['latitude' => 10]],
    'latitude out of range' => [['latitude' => 95, 'longitude' => 0]],
    'longitude out of range' => [['latitude' => 0, 'longitude' => 190]],
    'lower-case country' => [['country_code' => 'jo']],
]);
