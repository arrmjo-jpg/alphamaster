<?php

declare(strict_types=1);

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

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

test('public settings show returns 404 for a group with no public settings', function (): void {
    // 'security' exists but exposes nothing publicly: indistinguishable from absent.
    $this->getJson('/api/v1/settings/security')
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'SETTING_GROUP_NOT_FOUND');
});

test('public settings show returns 404 for an entirely unknown group', function (): void {
    $this->getJson('/api/v1/settings/no_such_group')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SETTING_GROUP_NOT_FOUND');
});

test('an unknown group never mints a cache entry of its own', function (): void {
    $store = Cache::getStore();

    foreach (['aaaa', 'bbbb', 'cccc'] as $bogus) {
        $this->getJson('/api/v1/settings/'.$bogus)->assertStatus(404);
        expect(Cache::has('settings:group:'.$bogus.':public'))->toBeFalse();
    }

    expect($store)->not->toBeNull();
});

test('route constraint rejects group names outside the identifier pattern', function (): void {
    $this->getJson('/api/v1/settings/NotAllowed')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

test('admin settings index denies unauthenticated request with 401', function (): void {
    $this->getJson('/api/v1/admin/settings')->assertStatus(401);
});

test('admin settings index denies token without admin:access ability with 403', function (): void {
    $this->withToken(adminToken(['user:access']))
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403);
});

test('admin settings index denies an authenticated non-admin even with the admin:access ability', function (): void {
    // Exercises the `admin` stage of the perimeter specifically: a valid token carrying
    // the right ability is still not enough when the user is not an administrator.
    $this->withToken(adminToken(['admin:access'], isAdmin: false))
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403);
});

test('admin with admin:access can list all settings with secrets properly masked', function (): void {
    // Provision the secret so the mask has something to hide.
    app(SettingServiceInterface::class)
        ->set('security', 'api_secret_key', 'provisioned-secret');

    $response = $this->withToken(adminToken())->getJson('/api/v1/admin/settings');

    $response->assertOk();
    $response->assertJsonPath('success', true);

    $secretItem = collect($response->json('data.security'))->firstWhere('key', 'api_secret_key');

    expect($secretItem['is_secret'])->toBeTrue()
        ->and($secretItem['value'])->toBe(Setting::SECRET_MASK);

    // The plaintext must not appear anywhere in the admin payload.
    expect($response->getContent())->not->toContain('provisioned-secret');
});

test('admin settings show returns 404 for an unknown group', function (): void {
    $this->withToken(adminToken())
        ->getJson('/api/v1/admin/settings/no_such_group')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SETTING_GROUP_NOT_FOUND');
});

test('admin can batch update settings within a group atomically', function (): void {
    $response = $this->withToken(adminToken())->putJson('/api/v1/admin/settings/general', [
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

test('admin batch update rejects invalid type casting strictly', function (): void {
    $this->withToken(adminToken())
        ->putJson('/api/v1/admin/settings/auth', [
            'settings' => ['password_min_length' => 'not-an-int'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_SETTING_VALUE');

    expect(setting('auth.password_min_length'))->toBe(8);
});

test('admin batch update rejects an array for a string setting instead of storing "Array"', function (): void {
    $this->withToken(adminToken())
        ->putJson('/api/v1/admin/settings/general', [
            'settings' => ['site_name' => ['nested' => 'payload']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_SETTING_VALUE');

    expect(setting('general.site_name'))->toBe('AlphaMaster Enterprise');
});

test('admin batch update reports an unknown key as 404 rather than an invalid value', function (): void {
    $this->withToken(adminToken())
        ->putJson('/api/v1/admin/settings/general', [
            'settings' => ['no_such_key' => 'value'],
        ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SETTING_KEY_NOT_FOUND');
});

test('admin batch update reports an unknown group as 404', function (): void {
    $this->withToken(adminToken())
        ->putJson('/api/v1/admin/settings/no_such_group', [
            'settings' => ['anything' => 'value'],
        ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SETTING_GROUP_NOT_FOUND');
});

test('a batch update that fails partway leaves the whole group untouched', function (): void {
    $this->withToken(adminToken())
        ->putJson('/api/v1/admin/settings/general', [
            'settings' => [
                'site_name' => 'Committed Before Failure',
                'maintenance_mode' => 'definitely-not-a-boolean',
            ],
        ])
        ->assertStatus(422);

    // The first key was saved inside the transaction, which must have rolled back.
    expect(setting('general.site_name'))->toBe('AlphaMaster Enterprise')
        ->and(setting('general.maintenance_mode'))->toBeFalse();
});
