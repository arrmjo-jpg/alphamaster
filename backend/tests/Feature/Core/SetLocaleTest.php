<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/api/v1/test-locale', function () {
        return response()->json([
            'success' => true,
            'locale' => app()->getLocale(),
        ]);
    })->middleware(['api']);
});

test('set locale middleware sets application locale dynamically from header without hardcoded restriction', function (string $locale): void {
    $response = $this->withHeader('X-Locale', $locale)
        ->getJson('/api/v1/test-locale');

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'locale' => $locale,
    ]);
})->with(['fr', 'de', 'es', 'ja', 'ar', 'en']);

test('set locale falls back to config app.locale when header is missing', function (): void {
    $defaultLocale = config('app.locale', 'en');

    $response = $this->getJson('/api/v1/test-locale');

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'locale' => $defaultLocale,
    ]);
});

test('Core module source files do not contain hardcoded language lists', function (): void {
    $setLocaleFile = file_get_contents(app_path('Modules/Core/Middleware/SetLocale.php'));

    expect($setLocaleFile)->not->toContain("['en', 'ar']")
        ->and($setLocaleFile)->not->toContain('["en", "ar"]');
});
