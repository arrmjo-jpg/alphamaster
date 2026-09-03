<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Models\Language;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LanguageSeeder::class);
});

test('public languages endpoint lists active languages with direction metadata', function (): void {
    $response = $this->getJson('/api/v1/languages');

    $response->assertOk();
    $response->assertJsonStructure([
        'success',
        'data' => [
            '*' => [
                'id',
                'code',
                'name',
                'native_name',
                'direction',
                'is_active',
                'is_default',
                'sort_order',
            ],
        ],
        'meta' => [
            'current_locale',
            'direction',
        ],
    ]);
});

test('admin languages endpoint denies unauthenticated request with 401', function (): void {
    $response = $this->getJson('/api/v1/admin/languages');

    $response->assertStatus(401);
    $response->assertJson([
        'success' => false,
        'error' => [
            'code' => 'UNAUTHENTICATED',
        ],
    ]);
});

test('admin languages endpoint denies admin whose token lacks admin:access ability with 403', function (): void {
    $adminUser = User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);

    // Issue token with only 'user:access' ability (lacking 'admin:access')
    $plainToken = $adminUser->createToken('regular-token', ['user:access'])->plainTextToken;

    $response = $this->withToken($plainToken)->getJson('/api/v1/admin/languages');

    $response->assertStatus(403);
    $response->assertJson([
        'success' => false,
        'error' => [
            'code' => 'FORBIDDEN',
        ],
    ]);
});

test('admin languages endpoint denies authenticated non-admin even if token has admin:access with 403', function (): void {
    $regularUser = User::create([
        'name' => 'Regular User',
        'email' => 'regular@example.com',
        'password' => bcrypt('secret'),
        'is_admin' => false,
    ]);

    $plainToken = $regularUser->createToken('attempted-admin-token', ['admin:access'])->plainTextToken;

    $response = $this->withToken($plainToken)->getJson('/api/v1/admin/languages');

    $response->assertStatus(403);
    $response->assertJson([
        'success' => false,
        'error' => [
            'code' => 'ADMIN_ACCESS_REQUIRED',
        ],
    ]);
});

test('admin with admin:access token ability is allowed to access and create language', function (): void {
    $adminUser = User::create([
        'name' => 'Valid Admin',
        'email' => 'validadmin@example.com',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);

    $plainToken = $adminUser->createToken('admin-token', ['admin:access'])->plainTextToken;

    $response = $this->withToken($plainToken)->postJson('/api/v1/admin/languages', [
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
        'direction' => 'ltr',
        'is_active' => true,
        'sort_order' => 3,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.code', 'fr');
    $this->assertDatabaseHas('languages', ['code' => 'fr']);
});

test('admin cannot deactivate the default application language', function (): void {
    $adminUser = User::create([
        'name' => 'Valid Admin',
        'email' => 'validadmin2@example.com',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);

    $plainToken = $adminUser->createToken('admin-token', ['admin:access'])->plainTextToken;
    $defaultLang = Language::where('is_default', true)->firstOrFail();

    $response = $this->withToken($plainToken)->patchJson("/api/v1/admin/languages/{$defaultLang->id}/status");

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'CANNOT_DEACTIVATE_DEFAULT_LANGUAGE');
});

test('admin can change the default language atomically', function (): void {
    $adminUser = User::create([
        'name' => 'Valid Admin',
        'email' => 'validadmin3@example.com',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);

    $plainToken = $adminUser->createToken('admin-token', ['admin:access'])->plainTextToken;
    $arabic = Language::where('code', 'ar')->firstOrFail();

    $response = $this->withToken($plainToken)->patchJson("/api/v1/admin/languages/{$arabic->id}/default");

    $response->assertOk();
    $response->assertJsonPath('data.is_default', true);

    expect(Language::where('code', 'ar')->value('is_default'))->toBeTrue()
        ->and(Language::where('code', 'en')->value('is_default'))->toBeFalse()
        ->and(Language::where('is_default', true)->count())->toBe(1);
});
