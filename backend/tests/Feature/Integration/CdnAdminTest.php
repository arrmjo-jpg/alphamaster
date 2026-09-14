<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Delivery\EdgeInvalidationKind;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\CdnPurgeStatus;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\CdnPurgeRequest;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * The CDN workspace's API (ADR 0053): vendor-neutral state, verification that reads the
 * plan from the vendor, purges that are queued and audited, and purge-everything behind
 * its own permission, a typed confirmation and a rate limit.
 */

uses(RefreshDatabase::class);

const CDN_ADMIN_ZONE = '9a7806061c88ada191ed06f989cc3dac';

beforeEach(function (): void {
    Cache::flush();
    Queue::fake();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);
    AuditRecord::query()->getQuery()->delete();

    $this->cloudflare = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::CDN)
        ->where('driver', 'cloudflare')
        ->firstOrFail();
});

function cdnAdminConfigure(mixed $test, ?string $scopeName = 'example.test'): void
{
    $test->cloudflare->setCredentials(['api_token' => 'cloudflare-admin-token']);
    $test->cloudflare->forceFill([
        'is_active' => true,
        'settings' => array_filter([
            'zone_id' => CDN_ADMIN_ZONE,
            'detected_scope_name' => $scopeName,
            'detected_plan' => $scopeName === null ? null : 'pro',
            'verified_at' => $scopeName === null ? null : now()->toIso8601String(),
        ], static fn ($value): bool => $value !== null),
    ])->save();
}

// ── Reading ──────────────────────────────────────────────────────────────────

test('the workspace is behind cdn.view', function (): void {
    $this->getJson('/api/v1/admin/cdn')->assertStatus(401);

    $this->withToken(tokenWithPermissions(['settings.view']))->getJson('/api/v1/admin/cdn')->assertStatus(403);

    // The guard keeps the first request's user; a second identity needs a fresh one.
    app('auth')->forgetGuards();

    $this->withToken(tokenWithPermissions(['cdn.view']))->getJson('/api/v1/admin/cdn')
        ->assertOk()
        ->assertJsonPath('data.configured', false)
        ->assertJsonPath('data.provider.driver', 'cloudflare')
        ->assertJsonPath('data.fields.settings', ['zone_id'])
        ->assertJsonPath('data.fields.credentials', ['api_token'])
        ->assertJsonPath('data.missing', ['zone_id', 'api_token'])
        ->assertJsonPath('data.verification', null)
        ->assertJsonPath('data.tag_header', null);
});

test('the workspace never reads a credential back, and reports detection apart from configuration', function (): void {
    cdnAdminConfigure($this);

    $response = $this->withToken(tokenWithPermissions(['cdn.view']))->getJson('/api/v1/admin/cdn')->assertOk();

    expect($response->getContent())->not->toContain('cloudflare-admin-token')
        ->and($response->json('data.configured'))->toBeTrue()
        ->and($response->json('data.provider.has_credentials'))->toBeTrue()
        ->and($response->json('data.provider.settings'))->toBe(['zone_id' => CDN_ADMIN_ZONE])
        ->and($response->json('data.verification.scope_name'))->toBe('example.test')
        ->and($response->json('data.verification.plan'))->toBe('pro')
        ->and($response->json('data.tag_header'))->toBe('Cache-Tag');

    $tags = collect($response->json('data.limits'))->keyBy('kind');

    expect($tags['tags']['requests_per_minute'])->toBe(300)
        ->and($tags['urls']['items_per_request'])->toBe(100);
});

// ── Configuring and verifying ─────────────────────────────────────────────────

test('the provider cannot be switched on without a zone and a token', function (): void {
    $token = adminToken(roles: ['super_admin']);

    $this->withToken($token)->putJson('/api/v1/admin/integrations/providers/'.$this->cloudflare->id, ['is_active' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PROVIDER_CONFIGURATION_INCOMPLETE')
        ->assertJsonPath('error.details.missing', ['zone_id', 'api_token']);

    $this->withToken($token)->putJson('/api/v1/admin/integrations/providers/'.$this->cloudflare->id, [
        'is_active' => true,
        'settings' => ['zone_id' => CDN_ADMIN_ZONE],
        'credentials' => ['api_token' => 'cloudflare-admin-token'],
    ])->assertOk()->assertJsonPath('data.has_credentials', true);
});

test('verification reads the scope and plan from the vendor, sets the limits, and is audited', function (): void {
    cdnAdminConfigure($this, scopeName: null);
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'success' => true,
        'errors' => [],
        'result' => ['name' => 'example.test', 'status' => 'active', 'plan' => ['legacy_id' => 'business', 'name' => 'Business Website']],
    ])]);

    $this->withToken(tokenWithPermissions(['integrations.update']))->postJson('/api/v1/admin/cdn/verify')
        ->assertOk()
        ->assertJsonPath('data.reachable', true)
        ->assertJsonPath('data.verification.scope_name', 'example.test')
        ->assertJsonPath('data.verification.plan', 'business');

    expect($this->cloudflare->refresh()->settings['detected_plan'])->toBe('business')
        ->and(AuditRecord::query()->where('action', AuditAction::CDN_SCOPE_VERIFIED)->first()?->context['reachable'] ?? null)->toBeTrue();
});

test('a failed verification keeps the last good detection and records the failure', function (): void {
    cdnAdminConfigure($this);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'Invalid access token']]], 403)]);

    $this->withToken(tokenWithPermissions(['integrations.update']))->postJson('/api/v1/admin/cdn/verify')
        ->assertOk()
        ->assertJsonPath('data.reachable', false)
        ->assertJsonPath('data.verification.error_code', 'CDN_AUTH_FAILED')
        ->assertJsonPath('data.verification.plan', 'pro');
});

test('changing the zone discards what was detected for the old one', function (): void {
    cdnAdminConfigure($this);

    $this->withToken(adminToken(roles: ['super_admin']))
        ->putJson('/api/v1/admin/integrations/providers/'.$this->cloudflare->id, ['settings' => ['zone_id' => str_repeat('b', 32)]])
        ->assertOk();

    expect($this->cloudflare->refresh()->settings)->toBe(['zone_id' => str_repeat('b', 32)]);
});

test('an edit that leaves the zone alone keeps the detection', function (): void {
    cdnAdminConfigure($this);

    $this->withToken(adminToken(roles: ['super_admin']))
        ->putJson('/api/v1/admin/integrations/providers/'.$this->cloudflare->id, ['label' => 'Cloudflare (production)', 'settings' => ['zone_id' => CDN_ADMIN_ZONE]])
        ->assertOk();

    expect($this->cloudflare->refresh()->settings['detected_plan'])->toBe('pro');
});

// ── Purging ──────────────────────────────────────────────────────────────────

test('purging needs a usable provider', function (): void {
    $this->withToken(tokenWithPermissions(['cdn.purge']))
        ->postJson('/api/v1/admin/cdn/purges', ['kind' => 'tags', 'items' => ['settings:public']])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CDN_NOT_CONFIGURED');
});

test('each item is validated against the edge rules, naming the item', function (): void {
    cdnAdminConfigure($this);

    $this->withToken(tokenWithPermissions(['cdn.purge']))
        ->postJson('/api/v1/admin/cdn/purges', ['kind' => 'urls', 'items' => ['https://cdn.example.test/ok.png', 'https://cdn.example.test/x.png#top']])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['items.1']]]);

    $this->withToken(tokenWithPermissions(['cdn.purge']))
        ->postJson('/api/v1/admin/cdn/purges', ['kind' => 'tags', 'items' => ['has space']])
        ->assertStatus(422);
});

test('a purge of named objects is queued, answered 202 with its rows, and audited', function (): void {
    cdnAdminConfigure($this);

    $response = $this->withToken(tokenWithPermissions(['cdn.purge']))
        ->postJson('/api/v1/admin/cdn/purges', ['kind' => 'urls', 'items' => ['https://cdn.example.test/a.png'], 'reason' => 'Replaced a logo'])
        ->assertStatus(202)
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.reason', 'Replaced a logo');

    expect(CdnPurgeRequest::query()->count())->toBe(1)
        ->and(AuditRecord::query()->where('action', AuditAction::CDN_PURGE_REQUESTED)->first()?->context['item_count'] ?? null)->toBe(1)
        ->and($response->json('data.0.kind_label'))->toBe('URLs');
});

test('purging everything needs its own permission, a verified scope, the scope name typed back, and is rate limited', function (): void {
    cdnAdminConfigure($this, scopeName: null);

    $this->withToken(tokenWithPermissions(['cdn.purge']))
        ->postJson('/api/v1/admin/cdn/purges', ['kind' => 'everything', 'confirm' => 'example.test'])
        ->assertStatus(403);

    app('auth')->forgetGuards();
    $token = tokenWithPermissions(['cdn.purge', 'cdn.purge_everything']);

    $this->withToken($token)->postJson('/api/v1/admin/cdn/purges', ['kind' => 'everything', 'confirm' => 'example.test'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CDN_NOT_VERIFIED');

    cdnAdminConfigure($this);

    $this->withToken($token)->postJson('/api/v1/admin/cdn/purges', ['kind' => 'everything', 'confirm' => 'wrong.test'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CDN_CONFIRMATION_MISMATCH');

    foreach (range(1, 3) as $ignored) {
        $this->withToken($token)->postJson('/api/v1/admin/cdn/purges', ['kind' => 'everything', 'confirm' => 'example.test'])
            ->assertStatus(202);
    }

    $this->withToken($token)->postJson('/api/v1/admin/cdn/purges', ['kind' => 'everything', 'confirm' => 'example.test'])
        ->assertStatus(429);

    expect(AuditRecord::query()->where('action', AuditAction::CDN_PURGE_EVERYTHING_REQUESTED)->count())->toBe(3);
});

test('only a failed purge can be retried, and a retry starts its attempts again', function (): void {
    cdnAdminConfigure($this);

    $failed = CdnPurgeRequest::query()->create([
        'integration_provider_id' => $this->cloudflare->id,
        'driver' => 'cloudflare',
        'kind' => EdgeInvalidationKind::TAGS,
        'items' => ['settings:public'],
        'item_count' => 1,
        'status' => CdnPurgeStatus::FAILED,
        'attempts' => 5,
        'error_code' => 'CDN_VENDOR_ERROR',
        'completed_at' => now(),
    ]);

    $token = tokenWithPermissions(['cdn.purge']);

    $this->withToken($token)->postJson('/api/v1/admin/cdn/purges/'.$failed->id.'/retry')
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.attempts', 0)
        ->assertJsonPath('data.error_code', null);

    $this->withToken($token)->postJson('/api/v1/admin/cdn/purges/'.$failed->id.'/retry')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CDN_PURGE_NOT_RETRYABLE');

    expect(AuditRecord::query()->where('action', AuditAction::CDN_PURGE_RETRIED)->count())->toBe(1);
});

test('the purge list filters by state', function (): void {
    cdnAdminConfigure($this);

    foreach ([CdnPurgeStatus::FAILED, CdnPurgeStatus::SUCCEEDED, CdnPurgeStatus::SUCCEEDED] as $status) {
        CdnPurgeRequest::query()->create([
            'integration_provider_id' => $this->cloudflare->id,
            'driver' => 'cloudflare',
            'kind' => EdgeInvalidationKind::URLS,
            'items' => ['https://cdn.example.test/a.png'],
            'item_count' => 1,
            'status' => $status,
        ]);
    }

    $token = tokenWithPermissions(['cdn.view']);

    $this->withToken($token)->getJson('/api/v1/admin/cdn/purges')->assertOk()->assertJsonPath('meta.total', 3);
    $this->withToken($token)->getJson('/api/v1/admin/cdn/purges?status=failed')->assertOk()->assertJsonPath('meta.total', 1);
});
