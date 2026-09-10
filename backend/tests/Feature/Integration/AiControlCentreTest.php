<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);
});

// The control centre: what an operator can find out about AI before they trust it.
//
// The three questions it keeps apart — is a vendor selected, does it hold a credential,
// does it answer — are separate because an interface that ran them together would send
// somebody to fix the wrong thing. A missing key and an unreachable vendor look
// identical from the outside and have nothing in common.

function configuredAi(): IntegrationProvider
{
    IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->update(['is_default' => false]);

    /** @var IntegrationProvider $provider */
    $provider = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->where('driver', 'openai')
        ->firstOrFail();

    $provider->setCredentials(['api_key' => 'sk-not-a-real-key']);
    $provider->forceFill(['is_active' => true, 'is_default' => true])->save();

    return $provider->refresh();
}

test('a fresh platform reports AI as unconfigured, with the drivers it could use', function (): void {
    $token = tokenWithPermissions(['integrations.view']);

    $response = $this->withToken($token)->getJson('/api/v1/admin/ai')->assertOk();

    expect($response->json('data.configured'))->toBeFalse()
        // No provider is *default and active*, which is the honest answer for a
        // platform where an operator has switched nothing on.
        ->and($response->json('data.provider'))->toBeNull()
        // What is possible, rather than only what somebody has already created: an
        // operator choosing a vendor should see the choice.
        ->and($response->json('data.available_drivers'))->toContain('openai')
        ->and($response->json('data.available_drivers'))->toContain('anthropic');
});

test('an active provider with no credential is reported as selected and unusable', function (): void {
    IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->where('driver', 'openai')
        ->update(['is_active' => true, 'is_default' => true]);

    $token = tokenWithPermissions(['integrations.view']);

    $response = $this->withToken($token)->getJson('/api/v1/admin/ai')->assertOk();

    // Two separate facts, and the difference is what an operator has to act on: the
    // vendor is chosen, and the key is missing.
    expect($response->json('data.configured'))->toBeFalse()
        ->and($response->json('data.provider.driver'))->toBe('openai')
        ->and($response->json('data.provider.has_credentials'))->toBeFalse();
});

test('the status never carries the credential, in any form', function (): void {
    configuredAi();
    $token = tokenWithPermissions(['integrations.view']);

    $body = $this->withToken($token)->getJson('/api/v1/admin/ai')->assertOk()->content();

    // The API has never read a credential back (ADR 0017), and a status endpoint is
    // exactly where that would be tempting.
    expect($body)->not->toContain('sk-not-a-real-key')
        ->and($body)->not->toContain('api_key');
});

test('the last attempt is reported from the usage log rather than remembered separately', function (): void {
    $provider = configuredAi();

    IntegrationUsageLog::query()->create([
        'integration_provider_id' => $provider->id,
        'capability' => IntegrationCapability::AI,
        'driver' => 'openai',
        'status' => UsageStatus::FAILURE,
        'error_code' => 'model_not_found',
        'error_message' => 'No such model.',
        'duration_ms' => 240,
    ]);

    $token = tokenWithPermissions(['integrations.view']);

    $response = $this->withToken($token)->getJson('/api/v1/admin/ai')->assertOk();

    expect($response->json('data.last_attempt.status'))->toBe('failure')
        ->and($response->json('data.last_attempt.error_code'))->toBe('model_not_found')
        // Enough to tell "it failed once" from "it has been failing", which is the
        // difference between a blip and a configuration that is wrong.
        ->and($response->json('data.recent_failures'))->toBe(1);
});

test('a check calls the vendor and reports that it answered', function (): void {
    configuredAi();
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => 'OK']]],
        'usage' => ['total_tokens' => 3],
    ], 200)]);

    $token = tokenWithPermissions(['integrations.view', 'ai.use']);

    $this->withToken($token)->postJson('/api/v1/admin/ai/check')
        ->assertOk()
        ->assertJsonPath('data.answered', true)
        ->assertJsonPath('data.units', 3);

    // A real call, because a check that did not call the vendor would only re-report
    // the configuration the operator is already looking at.
    Http::assertSentCount(1);
});

test('a check that fails is still a successful check', function (): void {
    configuredAi();
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Bad key.']], 401)]);

    $token = tokenWithPermissions(['integrations.view', 'ai.use']);

    $this->withToken($token)->postJson('/api/v1/admin/ai/check')
        // 200 with the finding in the payload. A 502 for a vendor that refused would
        // make a working diagnostic look broken.
        ->assertOk()
        ->assertJsonPath('data.answered', false)
        ->assertJsonPath('data.error_message', 'Bad key.');
});

test('a check with no provider configured says so without calling anybody', function (): void {
    Http::fake();
    $token = tokenWithPermissions(['integrations.view', 'ai.use']);

    $this->withToken($token)->postJson('/api/v1/admin/ai/check')
        ->assertOk()
        ->assertJsonPath('data.answered', false)
        ->assertJsonPath('data.error_code', 'NOT_CONFIGURED');

    Http::assertNothingSent();
});

// ── The boundary ─────────────────────────────────────────────────────────────

test('reading the state needs the permission that reads vendors', function (): void {
    $this->withToken(tokenWithPermissions(['audit.view']))
        ->getJson('/api/v1/admin/ai')
        ->assertForbidden();
});

test('running a check needs the permission that governs spending', function (): void {
    configuredAi();
    Http::fake();

    // May read the configuration, may not spend on the vendor.
    $this->withToken(tokenWithPermissions(['integrations.view']))
        ->postJson('/api/v1/admin/ai/check')
        ->assertForbidden();

    Http::assertNothingSent();
});

test('the control centre is behind the administrative perimeter', function (): void {
    $this->getJson('/api/v1/admin/ai')->assertUnauthorized();
    $this->postJson('/api/v1/admin/ai/check')->assertUnauthorized();
});
