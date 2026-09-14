<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Services\CdnEdgeCache;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
 * HTTP cache semantics (ADR 0036, ADR 0053 §2): nothing is cacheable unless its route names
 * a profile, a profile never makes an identified or failed response public, and a localized
 * response reaches the edge only when its language is in the address.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(LanguageSeeder::class);
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);
});

test('an anonymous public response with its language in the address is cacheable by browsers and the edge', function (): void {
    $response = $this->getJson('/api/v1/languages?locale=en')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60')
        ->and($response->headers->get('CDN-Cache-Control'))->toBe('public, max-age=300, stale-while-revalidate=60, stale-if-error=86400')
        ->and($response->headers->get('ETag'))->toStartWith('W/"')
        ->and($response->headers->get('Vary'))->toContain('Accept-Language')->toContain('X-Locale');
});

test('a language negotiated from a header keeps the response out of the edge', function (): void {
    $response = $this->withHeaders(['X-Locale' => 'en'])->getJson('/api/v1/languages')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->headers->get('CDN-Cache-Control'))->toBe('no-store');

    $viaAcceptLanguage = $this->withHeaders(['Accept-Language' => 'en'])->getJson('/api/v1/settings')->assertOk();

    expect($viaAcceptLanguage->headers->get('CDN-Cache-Control'))->toBe('no-store');
});

test('a request carrying a credential is never public, whatever the route declares', function (): void {
    $response = $this->withToken(adminToken(roles: ['super_admin']))->getJson('/api/v1/languages?locale=en')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->has('CDN-Cache-Control'))->toBeFalse()
        ->and($response->headers->has('ETag'))->toBeFalse();
});

test('a validator the client already holds is answered 304 with no body', function (): void {
    $etag = $this->getJson('/api/v1/languages?locale=en')->headers->get('ETag');

    $response = $this->withHeaders(['If-None-Match' => $etag])->get('/api/v1/languages?locale=en');

    expect($response->status())->toBe(304)
        ->and($response->getContent())->toBe('');
});

test('two languages never share a validator', function (): void {
    $english = $this->getJson('/api/v1/languages?locale=en')->headers->get('ETag');
    $arabic = $this->getJson('/api/v1/languages?locale=ar')->headers->get('ETag');

    expect($english)->not->toBe($arabic);
});

test('edge tags are written only once an edge that reads them is configured', function (): void {
    expect($this->getJson('/api/v1/settings?locale=en')->headers->has('Cache-Tag'))->toBeFalse();

    $row = IntegrationProvider::query()->forCapability(IntegrationCapability::CDN)->firstOrFail();
    $row->setCredentials(['api_token' => 'token']);
    $row->forceFill(['is_active' => true, 'settings' => ['zone_id' => str_repeat('a', 32)]])->save();
    app()->forgetInstance(CdnEdgeCache::class);
    app()->forgetInstance(EdgeCacheContract::class);

    expect($this->getJson('/api/v1/settings?locale=en')->headers->get('Cache-Tag'))->toBe('settings:public')
        ->and($this->getJson('/api/v1/languages?locale=en')->headers->get('Cache-Tag'))->toBe('localization:languages')
        // Not on a response the edge may not store.
        ->and($this->withHeaders(['X-Locale' => 'en'])->getJson('/api/v1/languages')->headers->has('Cache-Tag'))->toBeFalse();
});

test('an error from a cacheable route is not stored', function (): void {
    $response = $this->getJson('/api/v1/settings/no_such_group?locale=en')->assertStatus(404);

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->has('CDN-Cache-Control'))->toBeFalse();
});

test('an administrative response is no-store', function (): void {
    $response = $this->withToken(adminToken(roles: ['super_admin']))->getJson('/api/v1/admin/cache')->assertOk();

    expect($response->headers->get('Cache-Control'))->toBe('no-store, private');
});

test('a response that claims to be public without a profile is not trusted', function (): void {
    Route::middleware('api')->get('/api/v1/__unclassified_public', static fn () => response()->json(['ok' => true])
        ->header('Cache-Control', 'public, max-age=600'));

    $response = $this->getJson('/api/v1/__unclassified_public')->assertOk();

    expect($response->headers->get('Cache-Control'))->toBe('no-store, private');
});

test('a deliberately private response with a lifetime is left as its controller set it', function (): void {
    Route::middleware('api')->get('/api/v1/__private_immutable', static fn () => response()->json(['ok' => true])
        ->header('Cache-Control', 'private, max-age=31536000, immutable'));

    $response = $this->getJson('/api/v1/__private_immutable')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('immutable');
});
