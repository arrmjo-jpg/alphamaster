<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SettingSeeder::class);
});

test('public settings index returns only public settings without metadata leakage', function (): void {
    $response = $this->getJson('/api/v1/settings');

    $response->assertOk();
    $response->assertJsonStructure([
        'success',
        'data' => [
            'general' => [
                'site_name',
                'site_description',
                'maintenance_mode',
            ],
            'localization' => [
                'timezone',
                'date_format',
            ],
            'auth' => [
                'registration_enabled',
                'password_min_length',
            ],
        ],
    ]);

    // Ensure zero secrets or internal groups leaked
    $data = $response->json('data');
    expect($data)->not->toHaveKey('security')
        ->and($data['auth'])->not->toHaveKey('session_lifetime'); // Internal setting

    // Ensure zero metadata flags leaked in public API
    expect($response->json())->not->toHaveKey('is_secret')
        ->and($response->json())->not->toHaveKey('description');
});

test('public settings show returns public settings for specific group', function (): void {
    $response = $this->getJson('/api/v1/settings/general');

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'data' => [
            'site_name' => 'AlphaMaster Enterprise',
            'maintenance_mode' => false,
        ],
    ]);
});

test('admin settings index denies unauthenticated request with 401', function (): void {
    $response = $this->getJson('/api/v1/admin/settings');

    $response->assertStatus(401);
});

test('admin settings index denies token without admin:access ability with 403', function (): void {
    $admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);

    $token = $admin->createToken('user-token', ['user:access'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/admin/settings');

    $response->assertStatus(403);
});

test('admin with admin:access can list all settings with secrets properly masked', function (): void {
    $admin = User::create([
        'name' => 'Valid Admin',
        'email' => 'validadmin@example.com',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);

    $token = $admin->createToken('admin-token', ['admin:access'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/admin/settings');

    $response->assertOk();
    $response->assertJsonPath('success', true);

    // Verify secrets are masked
    $securitySettings = $response->json('data.security');
    $secretItem = collect($securitySettings)->firstWhere('key', 'api_secret_key');

    expect($secretItem['is_secret'])->toBeTrue()
        ->and($secretItem['value'])->toBe(Setting::SECRET_MASK);
});

test('admin can batch update settings within a group atomically', function (): void {
    $admin = User::create([
        'name' => 'Valid Admin',
        'email' => 'validadmin2@example.com',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);

    $token = $admin->createToken('admin-token', ['admin:access'])->plainTextToken;

    $response = $this->withToken($token)->putJson('/api/v1/admin/settings/general', [
        'settings' => [
            'site_name' => 'New Platform Name',
            'maintenance_mode' => true,
        ],
    ]);

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'data' => [
            'group' => 'general',
            'updated' => [
                'site_name' => 'New Platform Name',
                'maintenance_mode' => true,
            ],
        ],
    ]);

    expect(setting('general.site_name'))->toBe('New Platform Name')
        ->and(setting('general.maintenance_mode'))->toBeTrue();
});

test('admin batch update preserves existing secret when mask is submitted', function (): void {
    $admin = User::create([
        'name' => 'Valid Admin',
        'email' => 'validadmin3@example.com',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);

    $token = $admin->createToken('admin-token', ['admin:access'])->plainTextToken;

    $originalRawSecret = Setting::query()->where('group', 'security')->where('key', 'api_secret_key')->firstOrFail()->getRawValue();

    // Submit batch update sending the mask for secret
    $response = $this->withToken($token)->putJson('/api/v1/admin/settings/security', [
        'settings' => [
            'max_login_attempts' => 10,
            'api_secret_key' => Setting::SECRET_MASK,
        ],
    ]);

    $response->assertOk();

    // Verify max_login_attempts was updated but secret remained intact
    expect(setting('security.max_login_attempts'))->toBe(10)
        ->and(Setting::query()->where('group', 'security')->where('key', 'api_secret_key')->firstOrFail()->getRawValue())->toBe($originalRawSecret);
});

test('admin batch update rejects invalid type casting strictly', function (): void {
    $admin = User::create([
        'name' => 'Valid Admin',
        'email' => 'validadmin4@example.com',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);

    $token = $admin->createToken('admin-token', ['admin:access'])->plainTextToken;

    // Send invalid string for integer setting
    $response = $this->withToken($token)->putJson('/api/v1/admin/settings/auth', [
        'settings' => [
            'password_min_length' => 'not-an-int',
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'INVALID_SETTING_VALUE');
});
