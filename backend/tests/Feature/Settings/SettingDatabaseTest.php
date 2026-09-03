<?php

declare(strict_types=1);

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Enums\SettingType;
use App\Modules\Settings\Exceptions\SettingDecryptionException;
use App\Modules\Settings\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SettingSeeder::class);
});

/**
 * Insert a row through the query builder, bypassing the model entirely, so that the
 * assertion is about the database engine and not about Eloquent model events.
 *
 * @param  array<string, mixed>  $overrides
 */
/**
 * Run a statement that is expected to violate a database constraint, and report whether
 * it did.
 *
 * The statement is wrapped in a nested transaction (a SAVEPOINT) because PostgreSQL
 * aborts the enclosing transaction on any constraint violation — without the savepoint
 * every assertion after the expected failure would error out instead of running.
 */
function databaseRejects(callable $statement): bool
{
    try {
        DB::transaction(static fn () => $statement());

        return false;
    } catch (QueryException) {
        return true;
    }
}

function insertRawSetting(array $overrides = []): void
{
    DB::table('settings')->insert(array_merge([
        'id' => (string) Str::ulid(),
        'group' => 'raw',
        'key' => 'raw_key',
        'value' => 'raw_value',
        'type' => 'string',
        'is_secret' => false,
        'is_public' => false,
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

test('settings table persists records with valid ULID primary key', function (): void {
    $setting = Setting::query()->where('group', 'general')->where('key', 'site_name')->firstOrFail();

    expect($setting->id)->toBeString()
        ->and(strlen($setting->id))->toBe(26)
        ->and(Str::isUlid($setting->id))->toBeTrue();
});

test('database enforces unique constraint on group and key', function (): void {
    $this->expectException(QueryException::class);

    Setting::create([
        'group' => 'general',
        'key' => 'site_name',
        'value' => 'Duplicate Name',
        'type' => SettingType::STRING,
        'is_secret' => false,
        'is_public' => true,
    ]);
});

test('application layer rejects a setting that is both secret and public', function (): void {
    expect(function (): void {
        Setting::create([
            'group' => 'security',
            'key' => 'illegal_secret',
            'value' => 'secret_val',
            'type' => SettingType::STRING,
            'is_secret' => true,
            'is_public' => true, // Contradiction
        ]);
    })->toThrow(InvalidArgumentException::class);
});

test('database enforces the secret-never-public invariant on raw inserts', function (): void {
    // Bypasses the model guard entirely: this must be stopped by the engine.
    $rejected = databaseRejects(fn () => insertRawSetting([
        'group' => 'security',
        'key' => 'raw_illegal_secret',
        'is_secret' => true,
        'is_public' => true,
    ]));

    expect($rejected)->toBeTrue()
        ->and(DB::table('settings')->where('key', 'raw_illegal_secret')->exists())->toBeFalse();
});

test('database enforces the secret-never-public invariant on raw updates', function (): void {
    insertRawSetting(['group' => 'security', 'key' => 'raw_secret', 'is_secret' => true, 'is_public' => false]);

    $rejected = databaseRejects(
        fn () => DB::table('settings')->where('key', 'raw_secret')->update(['is_public' => true])
    );

    expect($rejected)->toBeTrue()
        ->and(DB::table('settings')->where('key', 'raw_secret')->value('is_public'))->toBeFalsy();
});

test('database rejects a type outside the SettingType enum on raw inserts', function (): void {
    $rejected = databaseRejects(fn () => insertRawSetting(['key' => 'bad_type', 'type' => 'not_a_type']));

    expect($rejected)->toBeTrue()
        ->and(DB::table('settings')->where('key', 'bad_type')->exists())->toBeFalse();
});

test('database rejects a type outside the SettingType enum on raw updates', function (): void {
    insertRawSetting(['key' => 'good_type', 'type' => 'string']);

    $rejected = databaseRejects(
        fn () => DB::table('settings')->where('key', 'good_type')->update(['type' => 'datetime'])
    );

    expect($rejected)->toBeTrue()
        ->and(DB::table('settings')->where('key', 'good_type')->value('type'))->toBe('string');
});

test('database accepts every declared SettingType value', function (): void {
    foreach (SettingType::values() as $type) {
        insertRawSetting(['key' => 'accepted_'.$type, 'type' => $type, 'value' => null]);
    }

    expect(DB::table('settings')->where('group', 'raw')->count())->toBe(count(SettingType::values()));
});

test('secret settings are encrypted at rest in the database', function (): void {
    $plaintext = 'super-secret-value-'.Str::random(8);

    app(SettingServiceInterface::class)
        ->set('security', 'api_secret_key', $plaintext);

    $secret = Setting::query()->where('group', 'security')->where('key', 'api_secret_key')->firstOrFail();
    $ciphertext = DB::table('settings')->where('id', $secret->id)->value('value');

    expect($secret->is_secret)->toBeTrue()
        ->and($ciphertext)->toBeString()
        ->and($ciphertext)->not->toBe($plaintext)
        ->and($ciphertext)->not->toContain($plaintext)
        ->and($secret->getRawValue())->toBe($plaintext);
});

test('an undecryptable secret raises an exception instead of leaking ciphertext', function (): void {
    // Simulate an APP_KEY rotation by storing a blob the current key cannot decrypt.
    DB::table('settings')
        ->where('group', 'security')
        ->where('key', 'api_secret_key')
        ->update(['value' => 'not-a-valid-laravel-ciphertext']);

    $secret = Setting::query()->where('group', 'security')->where('key', 'api_secret_key')->firstOrFail();

    expect(fn () => $secret->getRawValue())->toThrow(SettingDecryptionException::class)
        ->and(fn () => $secret->getTypedValue())->toThrow(SettingDecryptionException::class);
});
