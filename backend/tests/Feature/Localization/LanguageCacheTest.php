<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Enums\LanguageDirection;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Services\LocaleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LanguageSeeder::class);
});

test('active languages and default language are cached in Redis cache store', function (): void {
    $resolver = app(LocaleResolverInterface::class);

    // Warm up cache
    $resolver->getActiveLanguages();
    $resolver->getDefaultLanguageCode();

    expect(Cache::has(localizationKey(LocaleResolver::RESOURCE_ACTIVE)))->toBeTrue()
        ->and(Cache::has(localizationKey(LocaleResolver::RESOURCE_DEFAULT)))->toBeTrue()
        ->and(Cache::get(localizationKey(LocaleResolver::RESOURCE_DEFAULT)))->toBe('en');
});

test('cache is automatically invalidated when a new language is created', function (): void {
    $resolver = app(LocaleResolverInterface::class);
    $resolver->getActiveLanguages();
    expect(Cache::has(localizationKey(LocaleResolver::RESOURCE_ACTIVE)))->toBeTrue();

    Language::create([
        'code' => 'es',
        'name' => 'Spanish',
        'native_name' => 'Español',
        'direction' => LanguageDirection::LTR,
        'is_active' => true,
    ]);

    expect(Cache::has(localizationKey(LocaleResolver::RESOURCE_ACTIVE)))->toBeFalse();
});

test('cache is automatically invalidated when a language is updated or deleted', function (): void {
    $resolver = app(LocaleResolverInterface::class);
    $resolver->getActiveLanguages();
    expect(Cache::has(localizationKey(LocaleResolver::RESOURCE_ACTIVE)))->toBeTrue();

    $arabic = Language::where('code', 'ar')->firstOrFail();
    $arabic->update(['name' => 'Arabic Language Updated']);

    expect(Cache::has(localizationKey(LocaleResolver::RESOURCE_ACTIVE)))->toBeFalse();

    $resolver->getActiveLanguages();
    expect(Cache::has(localizationKey(LocaleResolver::RESOURCE_ACTIVE)))->toBeTrue();

    $arabic->delete();
    expect(Cache::has(localizationKey(LocaleResolver::RESOURCE_ACTIVE)))->toBeFalse();
});
