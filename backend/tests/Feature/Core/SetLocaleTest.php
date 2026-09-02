<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Enums\LanguageDirection;
use App\Modules\Localization\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::get('/api/v1/test-locale', function () {
        return response()->json([
            'success' => true,
            'locale' => app()->getLocale(),
        ]);
    })->middleware(['api']);

    $this->seed(LanguageSeeder::class);
});

test('Core SetLocale resolves dynamically from active DB records and falls back when deactivated', function (): void {
    // 1. Create a dynamic language in the DB (e.g. French 'fr')
    $french = Language::create([
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
        'direction' => LanguageDirection::LTR,
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 5,
    ]);

    // 2. Request X-Locale: fr -> Core resolves 'fr' dynamically
    $response = $this->withHeader('X-Locale', 'fr')
        ->getJson('/api/v1/test-locale');

    $response->assertOk();
    $response->assertHeader('Content-Language', 'fr');
    $response->assertHeader('X-Direction', 'ltr');
    $response->assertJson([
        'success' => true,
        'locale' => 'fr',
    ]);

    // 3. Deactivate the language in the DB
    $french->update(['is_active' => false]);

    // 4. Request X-Locale: fr again -> Falls back to DB default 'en'
    $responseFallback = $this->withHeader('X-Locale', 'fr')
        ->getJson('/api/v1/test-locale');

    $responseFallback->assertOk();
    $responseFallback->assertHeader('Content-Language', 'en');
    $responseFallback->assertJson([
        'success' => true,
        'locale' => 'en',
    ]);
});

test('Core SetLocale falls back to DB default when header is missing', function (): void {
    $response = $this->getJson('/api/v1/test-locale');

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'locale' => 'en',
    ]);
});

test('Core module source files do not contain hardcoded language lists', function (): void {
    $setLocaleFile = file_get_contents(app_path('Modules/Core/Middleware/SetLocale.php'));

    expect($setLocaleFile)->not->toContain("['en', 'ar']")
        ->and($setLocaleFile)->not->toContain('["en", "ar"]');
});
