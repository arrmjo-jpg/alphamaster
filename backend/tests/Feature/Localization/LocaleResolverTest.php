<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Enums\LanguageDirection;
use App\Modules\Localization\Models\Language;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LanguageSeeder::class);
});

test('resolver resolves explicit X-Locale header when language is active', function (): void {
    $resolver = app(LocaleResolverInterface::class);

    $request = Request::create('/api/v1/health');
    $request->headers->set('X-Locale', 'ar');

    expect($resolver->resolve($request))->toBe('ar')
        ->and($resolver->getDirection('ar'))->toBe('rtl');
});

test('resolver resolves explicit locale query parameter', function (): void {
    $resolver = app(LocaleResolverInterface::class);

    $request = Request::create('/api/v1/health?locale=ar');

    expect($resolver->resolve($request))->toBe('ar');
});

test('resolver falls back to default database language when requested locale is inactive', function (): void {
    Language::create([
        'code' => 'de',
        'name' => 'German',
        'native_name' => 'Deutsch',
        'direction' => LanguageDirection::LTR,
        'is_active' => false,
        'is_default' => false,
    ]);

    $resolver = app(LocaleResolverInterface::class);

    $request = Request::create('/api/v1/health');
    $request->headers->set('X-Locale', 'de');

    expect($resolver->resolve($request))->toBe('en');
});

test('resolver falls back to default database language when requested locale is unknown', function (): void {
    $resolver = app(LocaleResolverInterface::class);

    $request = Request::create('/api/v1/health');
    $request->headers->set('X-Locale', 'xx-nonexistent');

    expect($resolver->resolve($request))->toBe('en');
});

test('resolver negotiates Accept-Language header matching active language', function (): void {
    $resolver = app(LocaleResolverInterface::class);

    $request = Request::create('/api/v1/health');
    $request->headers->set('Accept-Language', 'ar-SA,ar;q=0.9,en-US;q=0.8');

    expect($resolver->resolve($request))->toBe('ar');
});

test('resolver respects authenticated user preferred locale if active', function (): void {
    $resolver = app(LocaleResolverInterface::class);

    $user = new class extends User
    {
        public string $preferred_locale = 'ar';
    };

    $request = Request::create('/api/v1/health');
    $request->setUserResolver(fn () => $user);

    expect($resolver->resolve($request))->toBe('ar');
});

test('getDirection returns correct direction from database metadata', function (): void {
    $resolver = app(LocaleResolverInterface::class);

    expect($resolver->getDirection('en'))->toBe('ltr')
        ->and($resolver->getDirection('ar'))->toBe('rtl')
        ->and($resolver->getDirection('unknown'))->toBe('ltr');
});
