<?php

declare(strict_types=1);

use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Enums\SettingType;
use App\Modules\Settings\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SettingSeeder::class);
});

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

test('database and application enforce invariant that secret settings cannot be public', function (): void {
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

test('secret settings are encrypted at rest in the database', function (): void {
    $secret = Setting::query()->where('group', 'security')->where('key', 'api_secret_key')->firstOrFail();

    expect($secret->is_secret)->toBeTrue();

    // Query raw column directly via DB facade to inspect stored ciphertext
    $rawColumn = DB::table('settings')->where('id', $secret->id)->value('value');

    expect($rawColumn)->not->toBeNull()
        ->and($rawColumn)->not->toBe('test-secret-value-for-dev')
        ->and($secret->getRawValue())->toBeString()
        ->and($secret->getRawValue())->not->toBe($rawColumn); // Decrypted differs from ciphertext
});
