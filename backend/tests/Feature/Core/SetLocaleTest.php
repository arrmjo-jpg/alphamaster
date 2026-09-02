<?php

declare(strict_types=1);

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

    // Seed active languages in database
    foreach (['fr', 'de', 'es', 'ja', 'ar', 'en'] as $index => $code) {
        Language::firstOrCreate(
            ['code' => $code],
            [
                'name' => ucfirst($code),
                'native_name' => ucfirst($code),
                'direction' => $code === 'ar' ? 'rtl' : 'ltr',
                'is_active' => true,
                'is_default' => $code === 'en',
                'sort_order' => $index + 1,
            ]
        );
    }
});

test('set locale middleware sets application locale dynamically from active database languages without hardcoded restriction', function (string $locale): void {
    $response = $this->withHeader('X-Locale', $locale)
        ->getJson('/api/v1/test-locale');

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'locale' => $locale,
    ]);
})->with(['fr', 'de', 'es', 'ja', 'ar', 'en']);

test('set locale falls back to default locale when requested locale is not in active DB languages', function (): void {
    $response = $this->withHeader('X-Locale', 'xx-unsupported')
        ->getJson('/api/v1/test-locale');

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'locale' => 'en',
    ]);
});

test('set locale falls back to default locale when header is missing', function (): void {
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
