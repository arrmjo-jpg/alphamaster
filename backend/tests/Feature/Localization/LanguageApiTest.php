<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Models\Language;
use Illuminate\Foundation\Auth\User;
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

test('admin languages endpoint denies unauthenticated and non-admin users', function (): void {
    $unauthenticated = $this->getJson('/api/v1/admin/languages');
    $unauthenticated->assertStatus(401);

    $regularUser = new class extends User
    {
        public $is_admin = false;
    };

    $forbidden = $this->actingAs($regularUser)->getJson('/api/v1/admin/languages');
    $forbidden->assertStatus(403);
});

test('admin can create a new language', function (): void {
    $admin = new class extends User
    {
        public $is_admin = true;
    };

    $response = $this->actingAs($admin)->postJson('/api/v1/admin/languages', [
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
    $admin = new class extends User
    {
        public $is_admin = true;
    };

    $defaultLang = Language::where('is_default', true)->firstOrFail();

    $response = $this->actingAs($admin)->patchJson("/api/v1/admin/languages/{$defaultLang->id}/status");

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'CANNOT_DEACTIVATE_DEFAULT_LANGUAGE');
});

test('admin can change the default language atomically', function (): void {
    $admin = new class extends User
    {
        public $is_admin = true;
    };

    $arabic = Language::where('code', 'ar')->firstOrFail();

    $response = $this->actingAs($admin)->patchJson("/api/v1/admin/languages/{$arabic->id}/default");

    $response->assertOk();
    $response->assertJsonPath('data.is_default', true);

    expect(Language::where('code', 'ar')->value('is_default'))->toBeTrue()
        ->and(Language::where('code', 'en')->value('is_default'))->toBeFalse()
        ->and(Language::where('is_default', true)->count())->toBe(1);
});
