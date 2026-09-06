<?php

declare(strict_types=1);

use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The key the platform cache is actually using for a settings resource.
 *
 * Asked for rather than composed: the layout belongs to the cache (ADR 0035), and a
 * test that rebuilt it by hand would keep asserting the shape this phase replaced.
 *
 * @param  array<string, string>  $discriminators
 */
function settingsKey(string $resource, array $discriminators = []): string
{
    return app(PlatformCacheContract::class)->key(CacheNamespace::SETTINGS, $resource, $discriminators);
}

beforeEach(function (): void {
    $this->seed(SettingSeeder::class);
    $this->service = app(SettingServiceInterface::class);
});

test('public settings cache is populated and segmented from internal caches', function (): void {
    $public = $this->service->getPublicSettings();

    expect(Cache::has(settingsKey(SettingService::RESOURCE_PUBLIC, ['locale' => app()->getLocale()])))->toBeTrue()
        ->and($public)->toHaveKey('general')
        ->and($public['general'])->toHaveKey('site_name')
        ->and($public)->not->toHaveKey('security'); // Security settings are not public
});

test('internal group cache allows server-side retrieval of settings', function (): void {
    expect($this->service->get('general.site_name'))->toBe('AlphaMaster Enterprise')
        ->and($this->service->get('security.max_login_attempts'))->toBe(5)
        ->and(Cache::has(settingsKey(SettingService::RESOURCE_GROUP_INDEX, ['group' => 'general', 'locale' => app()->getLocale()])))->toBeTrue()
        ->and(Cache::has(settingsKey(SettingService::RESOURCE_GROUP_INDEX, ['group' => 'security', 'locale' => app()->getLocale()])))->toBeTrue();
});

test('decrypted secrets are never written to the cache store', function (): void {
    $plaintext = 'plaintext-that-must-never-be-cached';
    $this->service->set('security', 'api_secret_key', $plaintext);

    // Warm every cache path that touches the security group.
    expect($this->service->get('security.api_secret_key'))->toBe($plaintext);
    $this->service->get('security.max_login_attempts');
    $this->service->getPublicSettings();

    $cachedGroup = Cache::get(settingsKey(SettingService::RESOURCE_GROUP_INDEX, ['group' => 'security', 'locale' => app()->getLocale()]));

    expect($cachedGroup)->toBeArray()
        // The secret contributes its key name only; its value stays out of the cache.
        ->and($cachedGroup['values'])->not->toHaveKey('api_secret_key')
        ->and($cachedGroup['secrets'])->toContain('api_secret_key')
        ->and(json_encode($cachedGroup))->not->toContain($plaintext)
        ->and(json_encode(Cache::get(settingsKey(SettingService::RESOURCE_PUBLIC, ['locale' => app()->getLocale()]))))->not->toContain($plaintext);
});

test('a cache entry left over in an older shape is rebuilt instead of faulting reads', function (): void {
    // The pre-review revision cached a flat [key => value] map under this key. A live
    // cache still holding that shape must not break every read after a deploy.
    Cache::put(
        settingsKey(SettingService::RESOURCE_GROUP_INDEX, ['group' => 'general', 'locale' => app()->getLocale()]),
        ['site_name' => 'Stale Flat Shape'],
        86400
    );

    expect($this->service->get('general.site_name'))->toBe('AlphaMaster Enterprise');

    $rebuilt = Cache::get(settingsKey(SettingService::RESOURCE_GROUP_INDEX, ['group' => 'general', 'locale' => app()->getLocale()]));

    expect($rebuilt)->toHaveKeys(['values', 'secrets'])
        ->and($rebuilt['values']['site_name'])->toBe('AlphaMaster Enterprise');
});

test('a secret still resolves correctly on a cold cache and on a warm one', function (): void {
    $this->service->set('security', 'api_secret_key', 'resolvable');

    $this->service->clearCache();
    expect($this->service->get('security.api_secret_key'))->toBe('resolvable');

    // Second read goes through the warmed group index and must agree.
    expect($this->service->get('security.api_secret_key'))->toBe('resolvable');
});

test('cache is automatically invalidated when a setting is updated', function (): void {
    $this->service->getPublicSettings();
    $this->service->get('general.site_name');
    expect(Cache::has(settingsKey(SettingService::RESOURCE_PUBLIC, ['locale' => app()->getLocale()])))->toBeTrue();

    $this->service->updateGroup('general', ['site_name' => 'AlphaMaster Updated Brand']);

    expect(Cache::has(settingsKey(SettingService::RESOURCE_PUBLIC, ['locale' => app()->getLocale()])))->toBeFalse()
        ->and(Cache::has(settingsKey(SettingService::RESOURCE_GROUP_INDEX, ['group' => 'general', 'locale' => app()->getLocale()])))->toBeFalse()
        ->and($this->service->get('general.site_name'))->toBe('AlphaMaster Updated Brand');
});

test('cache invalidation happens after the transaction commits, not inside it', function (): void {
    $this->service->getPublicSettings();
    $this->service->get('general.site_name');

    $cacheStillWarmAtCommitTime = null;

    DB::transaction(function () use (&$cacheStillWarmAtCommitTime): void {
        $this->service->updateGroup('general', ['site_name' => 'Deferred Invalidation']);

        // Still inside the outer transaction: the cache must not have been dropped yet,
        // otherwise a concurrent reader could repopulate it from pre-commit state.
        $cacheStillWarmAtCommitTime = Cache::has(settingsKey(SettingService::RESOURCE_GROUP_INDEX, ['group' => 'general', 'locale' => app()->getLocale()]));
    });

    expect($cacheStillWarmAtCommitTime)->toBeTrue()
        ->and(Cache::has(settingsKey(SettingService::RESOURCE_GROUP_INDEX, ['group' => 'general', 'locale' => app()->getLocale()])))->toBeFalse()
        ->and($this->service->get('general.site_name'))->toBe('Deferred Invalidation');
});

test('a rolled back update leaves no stale value cached', function (): void {
    $this->service->get('general.site_name');

    try {
        DB::transaction(function (): void {
            $this->service->updateGroup('general', ['site_name' => 'Never Committed']);

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($this->service->get('general.site_name'))->toBe('AlphaMaster Enterprise');
});

test('the public group listing is cached and invalidated with the rest', function (): void {
    $this->service->getPublicGroup('general');

    expect(Cache::has(settingsKey(SettingService::RESOURCE_PUBLIC_GROUPS)))->toBeTrue()
        ->and(Cache::has(settingsKey(SettingService::RESOURCE_PUBLIC_GROUP, ['group' => 'general', 'locale' => app()->getLocale()])))->toBeTrue();

    $this->service->updateGroup('general', ['site_name' => 'Invalidates Group Cache']);

    expect(Cache::has(settingsKey(SettingService::RESOURCE_PUBLIC_GROUPS)))->toBeFalse()
        ->and(Cache::has(settingsKey(SettingService::RESOURCE_PUBLIC_GROUP, ['group' => 'general', 'locale' => app()->getLocale()])))->toBeFalse()
        ->and($this->service->getPublicGroup('general')['site_name'])->toBe('Invalidates Group Cache');
});

test('global setting helper resolves values cleanly', function (): void {
    expect(setting('general.site_name'))->toBe('AlphaMaster Enterprise')
        ->and(setting('general.nonexistent', 'Fallback'))->toBe('Fallback');
});

test('a malformed setting reference is rejected', function (): void {
    expect(fn () => $this->service->get('no_group_separator'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->service->get('.leading_dot'))->toThrow(InvalidArgumentException::class);
});
