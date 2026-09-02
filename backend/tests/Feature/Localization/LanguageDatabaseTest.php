<?php

declare(strict_types=1);

use App\Modules\Localization\Enums\LanguageDirection;
use App\Modules\Localization\Models\Language;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('language table persists language attributes with valid ULID primary key', function (): void {
    $language = Language::create([
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
        'direction' => LanguageDirection::LTR,
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 3,
    ]);

    expect($language->id)->toBeString()
        ->and(strlen($language->id))->toBe(26)
        ->and($language->code)->toBe('fr')
        ->and($language->direction)->toBe(LanguageDirection::LTR)
        ->and($language->isLtr())->toBeTrue()
        ->and($language->isRtl())->toBeFalse();
});

test('code column enforces unique constraint', function (): void {
    Language::create([
        'code' => 'es',
        'name' => 'Spanish',
        'native_name' => 'Español',
        'direction' => LanguageDirection::LTR,
    ]);

    expect(fn () => Language::create([
        'code' => 'es',
        'name' => 'Spanish Duplicate',
        'native_name' => 'Español',
        'direction' => LanguageDirection::LTR,
    ]))->toThrow(QueryException::class);
});

test('database enforces single default language via partial unique index', function (): void {
    Language::create([
        'code' => 'en',
        'name' => 'English',
        'native_name' => 'English',
        'direction' => LanguageDirection::LTR,
        'is_active' => true,
        'is_default' => true,
    ]);

    expect(fn () => Language::create([
        'code' => 'ar',
        'name' => 'Arabic',
        'native_name' => 'العربية',
        'direction' => LanguageDirection::RTL,
        'is_active' => true,
        'is_default' => true,
    ]))->toThrow(QueryException::class);
});

test('active and ordered scopes filter and sort records correctly', function (): void {
    Language::create([
        'code' => 'de',
        'name' => 'German',
        'native_name' => 'Deutsch',
        'direction' => LanguageDirection::LTR,
        'is_active' => false,
        'sort_order' => 10,
    ]);

    Language::create([
        'code' => 'ja',
        'name' => 'Japanese',
        'native_name' => '日本語',
        'direction' => LanguageDirection::LTR,
        'is_active' => true,
        'sort_order' => 5,
    ]);

    Language::create([
        'code' => 'it',
        'name' => 'Italian',
        'native_name' => 'Italiano',
        'direction' => LanguageDirection::LTR,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $active = Language::query()->active()->ordered()->get();

    expect($active)->toHaveCount(2)
        ->and($active->pluck('code')->all())->toBe(['it', 'ja']);
});
