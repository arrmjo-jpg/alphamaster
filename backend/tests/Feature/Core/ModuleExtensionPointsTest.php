<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Requests\RoleRequest;
use App\Modules\Authorization\Services\PermissionCatalogue;
use App\Modules\Core\Cache\CacheNamespaceRegistry;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\SettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Support\ExtensionFixtures\CollidingCacheNamespace;
use Tests\Support\ExtensionFixtures\CompetitionCacheNamespace;
use Tests\Support\ExtensionFixtures\CompetitionPermission;
use Tests\Support\ExtensionFixtures\CompetitionSettingsCatalogue;

/*
 * A module the platform does not ship can own cached data, permissions and settings
 * without editing Core, Authorization or Settings (ADR 0052). The fixtures stand in for
 * that module; nothing here is a domain the platform implements.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

// ── Cache namespaces ─────────────────────────────────────────────────────────

test('a module-declared namespace works through the platform cache once registered', function (): void {
    app(CacheNamespaceRegistry::class)->register(...CompetitionCacheNamespace::cases());
    $cache = app(PlatformCacheContract::class);

    $cache->put(CompetitionCacheNamespace::STANDINGS, 'table', ['season' => '2026'], ['leader' => 'A']);

    expect($cache->get(CompetitionCacheNamespace::STANDINGS, 'table', ['season' => '2026']))->toBe(['leader' => 'A'])
        ->and($cache->key(CompetitionCacheNamespace::STANDINGS, 'table'))->toStartWith('competitions:table:');
});

test('an unregistered namespace is refused before the cache is touched, not silently bypassed', function (): void {
    $cache = app(PlatformCacheContract::class);
    $computed = false;

    expect(fn () => $cache->remember(CompetitionCacheNamespace::STANDINGS, 'table', [], function () use (&$computed): string {
        $computed = true;

        return 'value';
    }))->toThrow(LogicException::class, 'is not registered');

    // A fail-open namespace would otherwise have computed and returned the value, and
    // the missing declaration would never have been noticed.
    expect($computed)->toBeFalse();
});

test('a module cannot declare a namespace the platform already owns', function (): void {
    expect(fn () => app(CacheNamespaceRegistry::class)->register(CollidingCacheNamespace::SETTINGS))
        ->toThrow(LogicException::class, 'already declared');
});

test('the cache Admin lists a module namespace and honours what it declares about invalidation', function (): void {
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    AuditRecord::query()->getQuery()->delete();
    app(CacheNamespaceRegistry::class)->register(...CompetitionCacheNamespace::cases());

    $token = adminToken(roles: ['super_admin']);

    $rows = collect($this->withToken($token)->getJson('/api/v1/admin/cache')->assertOk()->json('data'))->keyBy('namespace');

    expect($rows['competitions']['ttl_seconds'])->toBe(600)
        ->and($rows['competitions']['flushable'])->toBeTrue()
        ->and($rows['competition_live']['flushable'])->toBeFalse()
        ->and($rows->has('settings'))->toBeTrue();

    $this->withToken($token)->postJson('/api/v1/admin/cache/competitions/flush')->assertOk();
    $this->withToken($token)->postJson('/api/v1/admin/cache/competition_live/flush')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CACHE_NAMESPACE_PROTECTED');

    expect(AuditRecord::query()->where('subject', 'competitions')->exists())->toBeTrue();
});

// ── Permissions ──────────────────────────────────────────────────────────────

test('a module permission enum is seeded, owned by its module, and given to super_admin only', function (): void {
    app(PermissionCatalogue::class)->register(CompetitionPermission::class);

    $this->seed(AdminPermissionSeeder::class);

    $permission = Permission::query()->where('name', 'competitions.publish')->firstOrFail();

    expect($permission->module)->toBe('competitions')
        ->and(Role::findByName('super_admin', 'web')->hasPermissionTo('competitions.publish'))->toBeTrue()
        ->and(Role::findByName('administrator', 'web')->hasPermissionTo('competitions.publish'))->toBeFalse();
});

test('a role may be given a registered module permission, and not an unregistered one', function (): void {
    $this->seed(AdminPermissionSeeder::class);

    $validate = fn (): bool => Validator::make(
        ['label' => 'Judges', 'permissions' => ['competitions.publish']],
        (new RoleRequest)->rules(),
    )->passes();

    expect($validate())->toBeFalse();

    app(PermissionCatalogue::class)->register(CompetitionPermission::class);

    expect($validate())->toBeTrue();
});

test('only a backed enum declaring permissions can be registered', function (): void {
    expect(fn () => app(PermissionCatalogue::class)->register(stdClass::class))
        ->toThrow(LogicException::class);
});

// ── Settings ─────────────────────────────────────────────────────────────────

test('a module settings catalogue registered against the registry is materialised and published', function (): void {
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    app(SettingRegistry::class)->registerCatalogue(new CompetitionSettingsCatalogue);

    $this->artisan('settings:sync')->assertSuccessful();

    expect(DB::table('settings')->where('group', 'competitions')->where('key', 'entries_per_page')->exists())->toBeTrue();

    $this->withToken(adminToken(roles: ['super_admin']))
        ->getJson('/api/v1/admin/settings/definitions')
        ->assertOk()
        ->assertJsonPath('data.competitions.0.key', 'competitions.entries_per_page');
});
