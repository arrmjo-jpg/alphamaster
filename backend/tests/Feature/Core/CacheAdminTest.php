<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * The application cache, as an operator manages it (ADR 0035, ADR 0051 §7): policies
 * and namespace invalidation, never keys.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    AuditRecord::query()->getQuery()->delete();
});

test('every namespace is listed with its policy, and the two protected ones say so', function (): void {
    $response = $this->withToken(adminToken(roles: ['super_admin']))->getJson('/api/v1/admin/cache')->assertOk();

    $rows = collect($response->json('data'))->keyBy('namespace');

    expect($rows->keys()->sort()->values()->all())->toBe(collect(CacheNamespace::cases())->map->value->sort()->values()->all())
        ->and($rows['auth']['flushable'])->toBeFalse()
        ->and($rows['auth']['failure_mode'])->toBe('fail_closed')
        ->and($rows['authorization']['flushable'])->toBeFalse()
        ->and($rows['settings']['flushable'])->toBeTrue()
        ->and($rows['settings']['ttl_seconds'])->toBe(86400);
});

test('invalidating a namespace moves its generation, and is recorded', function (): void {
    $cache = app(PlatformCacheContract::class);
    $cache->put(CacheNamespace::SETTINGS, 'probe', [], 'cached');
    $before = $cache->generation(CacheNamespace::SETTINGS);

    $this->withToken(adminToken(roles: ['super_admin']))->postJson('/api/v1/admin/cache/settings/flush')
        ->assertOk()->assertJsonPath('data.namespace', 'settings');

    $record = DB::table('audit_records')->where('action', AuditAction::CACHE_NAMESPACE_FLUSHED)->first();

    expect($cache->generation(CacheNamespace::SETTINGS))->toBeGreaterThan($before)
        ->and($cache->get(CacheNamespace::SETTINGS, 'probe'))->toBeNull()
        ->and($record?->subject)->toBe('settings');
});

test('auth and authorization cannot be invalidated here, and an unknown namespace does not exist', function (): void {
    $token = adminToken(roles: ['super_admin']);

    $this->withToken($token)->postJson('/api/v1/admin/cache/auth/flush')
        ->assertStatus(409)->assertJsonPath('error.code', 'CACHE_NAMESPACE_PROTECTED');
    $this->withToken($token)->postJson('/api/v1/admin/cache/authorization/flush')->assertStatus(409);
    $this->withToken($token)->postJson('/api/v1/admin/cache/nothing/flush')->assertStatus(404);

    expect(DB::table('audit_records')->where('action', AuditAction::CACHE_NAMESPACE_FLUSHED)->count())->toBe(0);
});

test('reading needs settings.view and invalidating needs settings.update', function (): void {
    $this->withToken(adminToken())->getJson('/api/v1/admin/cache')->assertStatus(403);

    resetClient($this);

    $viewer = tokenWithPermissions(['settings.view']);
    $this->withToken($viewer)->getJson('/api/v1/admin/cache')->assertOk();
    $this->withToken($viewer)->postJson('/api/v1/admin/cache/settings/flush')->assertStatus(403);

    resetClient($this);

    ['token' => $regular] = regularWithToken($this, 'not-an-operator@example.test');
    $this->withToken($regular)->getJson('/api/v1/admin/cache')->assertStatus(403);
});
