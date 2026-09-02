<?php

declare(strict_types=1);

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SettingSeeder::class);
});

test('public settings cache is populated and segmented from internal caches', function (): void {
    $service = app(SettingServiceInterface::class);

    $public = $service->getPublicSettings();

    expect(Cache::has(SettingService::CACHE_PREFIX.'public'))->toBeTrue()
        ->and($public)->toHaveKey('general')
        ->and($public['general'])->toHaveKey('site_name')
        ->and($public)->not->toHaveKey('security'); // Security settings are not public
});

test('internal group cache allows server-side retrieval of settings', function (): void {
    $service = app(SettingServiceInterface::class);

    $siteName = $service->get('general.site_name');
    $attempts = $service->get('security.max_login_attempts');

    expect($siteName)->toBe('AlphaMaster Enterprise')
        ->and($attempts)->toBe(5)
        ->and(Cache::has(SettingService::CACHE_PREFIX.'internal:group:general'))->toBeTrue()
        ->and(Cache::has(SettingService::CACHE_PREFIX.'internal:group:security'))->toBeTrue();
});

test('cache is automatically invalidated when a setting is updated', function (): void {
    $service = app(SettingServiceInterface::class);

    // Warm cache
    $service->getPublicSettings();
    $service->get('general.site_name');
    expect(Cache::has(SettingService::CACHE_PREFIX.'public'))->toBeTrue();

    // Update setting via model or service
    $service->updateGroup('general', [
        'site_name' => 'AlphaMaster Updated Brand',
    ]);

    expect(Cache::has(SettingService::CACHE_PREFIX.'public'))->toBeFalse()
        ->and(Cache::has(SettingService::CACHE_PREFIX.'internal:group:general'))->toBeFalse()
        ->and($service->get('general.site_name'))->toBe('AlphaMaster Updated Brand');
});

test('global setting helper resolves values cleanly', function (): void {
    expect(setting('general.site_name'))->toBe('AlphaMaster Enterprise')
        ->and(setting('general.nonexistent', 'Fallback'))->toBe('Fallback');
});
