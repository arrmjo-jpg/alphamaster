<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Delivery\EdgeInvalidationKind;
use App\Modules\Core\Delivery\EdgeInvalidationReceipt;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\CdnPurgeStatus;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Jobs\ProcessCdnPurgeRequest;
use App\Modules\Integration\Jobs\SweepCdnPurgeRequests;
use App\Modules\Integration\Models\CdnPurgeRequest;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Integration\Services\CdnEdgeCache;
use App\Modules\Localization\Services\LocaleResolver;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Edge invalidation through the CDN capability (ADR 0053): recorded, split to the vendor's
 * limits, processed by a worker that claims its row, retried only when the vendor could
 * later succeed, and never reported as purged unless the vendor accepted it.
 */

uses(RefreshDatabase::class);

const CDN_TEST_ZONE = '023e105f4ecef8ad9ca31a8372d0c353';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);
});

function cloudflareRow(): IntegrationProvider
{
    return IntegrationProvider::query()
        ->forCapability(IntegrationCapability::CDN)
        ->where('driver', 'cloudflare')
        ->firstOrFail();
}

function configureCloudflare(?string $plan = null, bool $active = true): IntegrationProvider
{
    $row = cloudflareRow();
    $row->setCredentials(['api_token' => 'cloudflare-test-token']);
    $row->forceFill([
        'is_active' => $active,
        'settings' => array_filter([
            'zone_id' => CDN_TEST_ZONE,
            'detected_plan' => $plan,
            'detected_scope_name' => $plan === null ? null : 'example.test',
            'verified_at' => $plan === null ? null : now()->toIso8601String(),
        ], static fn ($value): bool => $value !== null),
    ])->save();

    return $row->refresh();
}

function purgeRow(array $attributes = []): CdnPurgeRequest
{
    $provider = cloudflareRow();

    return CdnPurgeRequest::query()->create(array_merge([
        'integration_provider_id' => $provider->id,
        'driver' => 'cloudflare',
        'kind' => EdgeInvalidationKind::URLS,
        'items' => ['https://cdn.example.test/a.png'],
        'item_count' => 1,
        'status' => CdnPurgeStatus::PENDING,
    ], $attributes));
}

function runPurge(CdnPurgeRequest $row): CdnPurgeRequest
{
    (new ProcessCdnPurgeRequest($row->id))->handle(app(CdnEdgeCache::class));

    return $row->refresh();
}

function cloudflareAnswers(int $status, array $body = [], array $headers = []): void
{
    Http::fake([
        'api.cloudflare.com/*' => Http::response($body + ['success' => $status < 300, 'errors' => [], 'result' => ['id' => 'purge-ref']], $status, $headers),
    ]);
}

// ── Queueing ─────────────────────────────────────────────────────────────────

test('with no active, configured CDN an invalidation is reported as not configured and nothing is recorded', function (): void {
    Queue::fake();

    $receipt = app(EdgeCacheContract::class)->invalidate(EdgeInvalidation::tags(['settings:public']));

    expect($receipt->status)->toBe(EdgeInvalidationReceipt::NOT_CONFIGURED)
        ->and(CdnPurgeRequest::query()->count())->toBe(0);

    configureCloudflare(active: false);

    expect(app(EdgeCacheContract::class)->invalidate(EdgeInvalidation::tags(['settings:public']))->status)
        ->toBe(EdgeInvalidationReceipt::NOT_CONFIGURED);

    Queue::assertNothingPushed();
});

test('an invalidation is split to the vendor limit, one recorded row and one job per call', function (): void {
    Queue::fake();
    configureCloudflare();

    $urls = array_map(static fn (int $i): string => 'https://cdn.example.test/file-'.$i.'.png', range(1, 250));
    $receipt = app(EdgeCacheContract::class)->invalidate(EdgeInvalidation::urls($urls), 'test');

    expect($receipt->queued())->toBeTrue()
        ->and($receipt->requestIds)->toHaveCount(3)
        ->and(CdnPurgeRequest::query()->pluck('item_count')->sort()->values()->all())->toBe([50, 100, 100])
        ->and(CdnPurgeRequest::query()->where('status', 'pending')->count())->toBe(3);

    Queue::assertPushed(ProcessCdnPurgeRequest::class, 3);
});

test('the tag header is the driver\'s once a provider is usable, and absent before', function (): void {
    expect(app(EdgeCacheContract::class)->tagHeader())->toBeNull();

    configureCloudflare();
    app()->forgetInstance(CdnEdgeCache::class);
    app()->forgetInstance(EdgeCacheContract::class);

    expect(app(EdgeCacheContract::class)->tagHeader())->toBe('Cache-Tag');
});

// ── Processing ───────────────────────────────────────────────────────────────

test('a purge the vendor accepts is recorded as succeeded, with its reference and a usage log entry', function (): void {
    configureCloudflare();
    cloudflareAnswers(200);

    $row = runPurge(purgeRow([
        'kind' => EdgeInvalidationKind::PREFIXES,
        'items' => ['https://cdn.example.test/images'],
    ]));

    expect($row->status)->toBe(CdnPurgeStatus::SUCCEEDED)
        ->and($row->provider_reference)->toBe('purge-ref')
        ->and($row->attempts)->toBe(1)
        ->and($row->completed_at)->not->toBeNull()
        ->and(IntegrationUsageLog::query()->where('capability', 'cdn')->where('status', 'success')->count())->toBe(1);

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/zones/'.CDN_TEST_ZONE.'/purge_cache'
        && $request->hasHeader('Authorization', 'Bearer cloudflare-test-token')
        // Cloudflare names a prefix without its scheme.
        && $request['prefixes'] === ['cdn.example.test/images']);
});

test('each kind reaches the vendor in the vendor\'s own shape', function (EdgeInvalidationKind $kind, array $items, array $expected): void {
    configureCloudflare();
    cloudflareAnswers(200);

    runPurge(purgeRow(['kind' => $kind, 'items' => $items, 'item_count' => count($items)]));

    Http::assertSent(static fn (Request $request): bool => $request->data() === $expected);
})->with([
    'urls' => [EdgeInvalidationKind::URLS, ['https://cdn.example.test/a.png'], ['files' => ['https://cdn.example.test/a.png']]],
    'tags' => [EdgeInvalidationKind::TAGS, ['settings:public'], ['tags' => ['settings:public']]],
    'hosts' => [EdgeInvalidationKind::HOSTS, ['cdn.example.test'], ['hosts' => ['cdn.example.test']]],
    'everything' => [EdgeInvalidationKind::EVERYTHING, [], ['purge_everything' => true]],
]);

test('a rate-limited purge waits for the vendor\'s Retry-After and stays visible as pending', function (): void {
    Queue::fake();
    configureCloudflare();
    cloudflareAnswers(429, ['errors' => [['code' => 971, 'message' => 'Please wait and consider throttling your request speed']]], ['Retry-After' => '120']);

    $row = runPurge(purgeRow());

    expect($row->status)->toBe(CdnPurgeStatus::PENDING)
        ->and($row->attempts)->toBe(1)
        ->and($row->error_code)->toBe('CDN_RATE_LIMITED')
        ->and($row->error_message)->toContain('throttling')
        ->and(abs($row->available_at->diffInSeconds(now()->addSeconds(120))))->toBeLessThan(5);

    Queue::assertPushed(ProcessCdnPurgeRequest::class, static fn (ProcessCdnPurgeRequest $job): bool => $job->requestId === $row->id && $job->delay !== null);
});

test('a refused credential fails the purge at once and says so', function (): void {
    configureCloudflare();
    cloudflareAnswers(403, ['errors' => [['code' => 10000, 'message' => 'Authentication error']]]);

    $row = runPurge(purgeRow());

    expect($row->status)->toBe(CdnPurgeStatus::FAILED)
        ->and($row->error_code)->toBe('CDN_AUTH_FAILED')
        ->and($row->completed_at)->not->toBeNull();
});

test('a vendor outage is retried, and the last allowed attempt fails for good', function (): void {
    Queue::fake();
    configureCloudflare();
    cloudflareAnswers(502);

    $row = runPurge(purgeRow());

    expect($row->status)->toBe(CdnPurgeStatus::PENDING)
        ->and($row->error_code)->toBe('CDN_VENDOR_ERROR');

    $row->forceFill(['attempts' => ProcessCdnPurgeRequest::MAX_ATTEMPTS - 1, 'available_at' => null])->save();

    expect(runPurge($row)->status)->toBe(CdnPurgeStatus::FAILED);
});

test('a spent plan budget defers the purge without calling the vendor or counting an attempt', function (): void {
    Queue::fake();
    Http::fake();
    $provider = configureCloudflare(plan: 'free');

    $key = 'cdn-purge:'.$provider->id.':bulk';
    RateLimiter::clear($key);
    foreach (range(1, 5) as $ignored) {
        RateLimiter::hit($key, 60);
    }

    $row = runPurge(purgeRow(['kind' => EdgeInvalidationKind::TAGS, 'items' => ['settings:public']]));

    expect($row->status)->toBe(CdnPurgeStatus::PENDING)
        ->and($row->attempts)->toBe(0)
        ->and($row->available_at)->not->toBeNull();

    Http::assertNothingSent();
    RateLimiter::clear($key);
});

test('the vendor\'s final answer to a purge of everything is written to the audit trail', function (): void {
    configureCloudflare();
    cloudflareAnswers(200);

    $succeeded = runPurge(purgeRow(['kind' => EdgeInvalidationKind::EVERYTHING, 'items' => [], 'item_count' => 0]));

    $record = AuditRecord::query()->where('action', AuditAction::CDN_PURGE_EVERYTHING_COMPLETED)->where('subject', $succeeded->id)->firstOrFail();

    expect($record->outcome)->not->toBe('failed')
        ->and($record->context['provider_reference'])->toBe('purge-ref');
});

test('a purge of everything the vendor refuses is audited as failed, with the vendor\'s code', function (): void {
    configureCloudflare();
    cloudflareAnswers(403, ['errors' => [['message' => 'Authentication error']]]);

    $failed = runPurge(purgeRow(['kind' => EdgeInvalidationKind::EVERYTHING, 'items' => [], 'item_count' => 0]));

    $record = AuditRecord::query()->where('action', AuditAction::CDN_PURGE_EVERYTHING_COMPLETED)->where('subject', $failed->id)->firstOrFail();

    expect($record->outcome)->toBe('failed')
        ->and($record->context['error_code'])->toBe('CDN_AUTH_FAILED');
});

test('a purge of named objects leaves the audit trail alone; its outcome is on the row', function (): void {
    configureCloudflare();
    cloudflareAnswers(200);

    runPurge(purgeRow());

    expect(AuditRecord::query()->where('action', AuditAction::CDN_PURGE_EVERYTHING_COMPLETED)->count())->toBe(0);
});

test('a row another worker already claimed is left alone', function (): void {
    Http::fake();
    configureCloudflare();

    $row = runPurge(purgeRow(['status' => CdnPurgeStatus::PROCESSING, 'attempts' => 1]));

    expect($row->status)->toBe(CdnPurgeStatus::PROCESSING)
        ->and($row->attempts)->toBe(1);

    Http::assertNothingSent();
});

test('a purge whose provider was switched off after queueing fails rather than waiting forever', function (): void {
    Http::fake();
    configureCloudflare(active: false);

    $row = runPurge(purgeRow());

    expect($row->status)->toBe(CdnPurgeStatus::FAILED)
        ->and($row->error_code)->toBe('CDN_NOT_CONFIGURED');

    Http::assertNothingSent();
});

test('the sweep recovers a purge a dead worker left in progress and one whose dispatch was lost', function (): void {
    Queue::fake();
    configureCloudflare();

    $stuck = purgeRow(['status' => CdnPurgeStatus::PROCESSING, 'attempts' => 1]);
    $lost = purgeRow();
    CdnPurgeRequest::query()->whereKey([$stuck->id, $lost->id])->update(['updated_at' => now()->subHour()]);

    (new SweepCdnPurgeRequests)->handle();

    expect($stuck->refresh()->status)->toBe(CdnPurgeStatus::PENDING);

    Queue::assertPushed(ProcessCdnPurgeRequest::class, static fn (ProcessCdnPurgeRequest $job): bool => $job->requestId === $stuck->id);
    Queue::assertPushed(ProcessCdnPurgeRequest::class, static fn (ProcessCdnPurgeRequest $job): bool => $job->requestId === $lost->id);
});

// ── The platform's own modules invalidate the edge ─────────────────────────────

test('a settings change purges the public settings tag, and a language change the language tag', function (): void {
    Queue::fake();
    configureCloudflare();

    app(SettingService::class)->clearCache();
    app(LocaleResolver::class)->clearCache();

    $tags = CdnPurgeRequest::query()->where('kind', 'tags')->get()->flatMap->items->all();

    expect($tags)->toContain(SettingService::publicEdgeTag(), LocaleResolver::edgeTag())
        ->and(SettingService::publicEdgeTag())->toBe('settings:public')
        ->and(LocaleResolver::edgeTag())->toBe('localization:languages');
});

test('invalidating without a CDN is harmless to the change that asked', function (): void {
    app(SettingService::class)->clearCache();
    app(LocaleResolver::class)->clearCache();

    expect(CdnPurgeRequest::query()->count())->toBe(0);
});
