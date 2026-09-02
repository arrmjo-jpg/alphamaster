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

test('resolver normalizes en-US to en and resolves active en locale', function (): void {
    $resolver = app(LocaleResolverInterface::class);

    $request = Request::create('/api/v1/health');
    $request->headers->set('X-Locale', 'en-US');

    expect($resolver->resolve($request))->toBe('en');
});

test('resolver negotiates Accept-Language header matching active language', function (): void {
    $resolver = app(LocaleResolverInterface::class);

    $request = Request::create('/api/v1/health');
    $request->headers->set('Accept-Language', 'ar-SA,ar;q=0.9,en-US;q=0.8');

    expect($resolver->resolve($request))->toBe('ar');
});

test('resolver excludes q=0 entries and rejects malformed quality weights in Accept-Language', function (): void {
    $resolver = app(LocaleResolverInterface::class);

    // en is marked with q=0 (not acceptable), ar has valid weight 0.5 -> ar must be selected
    $request = Request::create('/api/v1/health');
    $request->headers->set('Accept-Language', 'en;q=0,ar;q=0.5');
    expect($resolver->resolve($request))->toBe('ar');

    // Malformed quality value q=invalid must not beat valid weight 0.8
    $requestMalformed = Request::create('/api/v1/health');
    $requestMalformed->headers->set('Accept-Language', 'en;q=invalid,ar;q=0.8');
    expect($resolver->resolve($requestMalformed))->toBe('ar');

    // Wildcard '*' must not bypass active language validation
    $requestWildcard = Request::create('/api/v1/health');
    $requestWildcard->headers->set('Accept-Language', '*,ar;q=0.1');
    expect($resolver->resolve($requestWildcard))->toBe('ar');
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
