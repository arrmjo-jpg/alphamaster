<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->service = app(SettingServiceInterface::class);
    $this->token = adminToken(roles: ['administrator']);
});

/** A group update carrying a precondition. */
function updateWith(mixed $test, string $group, array $settings, ?string $version): mixed
{
    $headers = $version === null ? [] : ['If-Match' => $version];

    return $test->withToken($test->token)
        ->withHeaders($headers)
        ->putJson('/api/v1/admin/settings/'.$group, ['settings' => $settings]);
}

// ── The validator ────────────────────────────────────────────────────────────

test('a read hands back the version a write will need', function (): void {
    $response = $this->withToken($this->token)->getJson('/api/v1/admin/settings/general');

    $response->assertOk();

    expect($response->json('meta.version'))->toBeString()->not->toBeEmpty()
        // Both ways: an ETag for a client that speaks conditional requests, and meta
        // for one that does not.
        ->and($response->headers->get('ETag'))->toBe('"'.$response->json('meta.version').'"');
});

test('the version changes when the group changes, and not otherwise', function (): void {
    $before = $this->service->groupVersion('general');

    expect($this->service->groupVersion('general'))->toBe($before);

    $this->service->set('general', 'comments_enabled', true);

    expect($this->service->groupVersion('general'))->not->toBe($before);
});

test('a localized write moves the version too', function (): void {
    // A localized write touches only setting_translations and leaves settings
    // untouched, so a version derived from the rows alone would let two people edit
    // the same Arabic site name and never conflict.
    $before = $this->service->groupVersion('general');

    app()->setLocale('ar');
    $this->service->set('general', 'site_name', 'اسم عربي');

    expect($this->service->groupVersion('general'))->not->toBe($before);
});

test('one group changing does not move another group version', function (): void {
    $auth = $this->service->groupVersion('auth');

    $this->service->set('general', 'comments_enabled', true);

    expect($this->service->groupVersion('auth'))->toBe($auth);
});

// ── A stale write is refused ─────────────────────────────────────────────────

test('a write built on a stale read is refused with 412', function (): void {
    // Two administrators load the same group.
    $adminA = $this->service->groupVersion('general');
    $adminB = $adminA;

    // The first writes.
    updateWith($this, 'general', ['comments_enabled' => true], $adminA)->assertOk();

    // The second writes from what they loaded a minute earlier.
    $conflict = updateWith($this, 'general', ['comments_enabled' => false], $adminB);

    $conflict->assertStatus(412)
        ->assertJsonPath('error.code', 'SETTING_VERSION_CONFLICT');

    // The first administrator's change stands.
    expect(setting('general.comments_enabled'))->toBeTrue();
});

test('the refusal carries the current version so a client can recover', function (): void {
    $stale = $this->service->groupVersion('general');
    updateWith($this, 'general', ['comments_enabled' => true], $stale)->assertOk();

    $conflict = updateWith($this, 'general', ['comments_enabled' => false], $stale);

    expect($conflict->json('error.details.current_version'))
        ->toBe($this->service->groupVersion('general'));
});

test('retrying with the current version succeeds', function (): void {
    $stale = $this->service->groupVersion('general');
    updateWith($this, 'general', ['comments_enabled' => true], $stale)->assertOk();

    updateWith($this, 'general', ['comments_enabled' => false], $stale)->assertStatus(412);

    // Read again, then write: the ordinary recovery.
    updateWith($this, 'general', ['comments_enabled' => false], $this->service->groupVersion('general'))
        ->assertOk();

    expect(setting('general.comments_enabled'))->toBeFalse();
});

// ── A missing precondition is not a way around it ────────────────────────────

test('an update carrying no version is refused rather than waved through', function (): void {
    // The behaviour this exists to end must not be reachable by omitting a header.
    $before = setting('general.comments_enabled');

    $response = updateWith($this, 'general', ['comments_enabled' => true], null);

    $response->assertStatus(428)
        ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

    expect(setting('general.comments_enabled'))->toBe($before);
});

test('an empty or quoted-empty precondition is treated as missing', function (): void {
    foreach (['', '""'] as $value) {
        $this->withToken($this->token)
            ->withHeaders(['If-Match' => $value])
            ->putJson('/api/v1/admin/settings/general', ['settings' => ['comments_enabled' => true]])
            ->assertStatus(428);
    }
});

test('a precondition is required for every group, not only the ones under test', function (): void {
    $changes = [
        'general' => ['comments_enabled' => true],
        'auth' => ['registration_enabled' => false],
        'security' => ['max_login_attempts' => 9],
        'mail' => ['host' => 'smtp.example.test'],
        'branding' => ['watermark_opacity' => 40],
    ];

    foreach ($changes as $group => $settings) {
        updateWith($this, $group, $settings, null)
            ->assertStatus(428, $group.' accepted a write with no precondition');
    }
});

// ── Ordering: the precondition does not mask other refusals ─────────────────

test('an unknown group is still 404 even with a precondition supplied', function (): void {
    $this->withToken($this->token)
        ->withHeaders(['If-Match' => 'anything'])
        ->putJson('/api/v1/admin/settings/no_such_group', ['settings' => ['x' => 1]])
        ->assertStatus(404);
});

test('a stale write carrying a bad value applies neither', function (): void {
    // Structural validation happens in the FormRequest, before the controller is
    // entered, so this is refused as malformed rather than as stale. Which refusal
    // wins is not the property worth asserting — that nothing is applied is.
    $stale = $this->service->groupVersion('auth');
    updateWith($this, 'auth', ['registration_enabled' => false], $stale)->assertOk();

    $response = updateWith($this, 'auth', ['password_min_length' => 'not-a-number'], $stale);

    expect($response->status())->toBeIn([412, 422])
        ->and(setting('auth.password_min_length'))->toBe(8)
        // The earlier write stands; the refused one changed nothing.
        ->and(setting('auth.registration_enabled'))->toBeFalse();
});

// ── Secret rotation keeps its semantics under the precondition ──────────────

test('rotating a secret still works, and moves the version', function (): void {
    $token = adminToken(roles: ['super_admin']);

    $version = $this->service->groupVersion('security');

    $this->withToken($token)
        ->withHeaders(['If-Match' => $version])
        ->putJson('/api/v1/admin/settings/security', ['settings' => ['api_secret_key' => 'first']])
        ->assertOk();

    expect($this->service->groupVersion('security'))->not->toBe($version)
        ->and($this->service->get('security.api_secret_key'))->toBe('first');
});

test('a stale rotation is refused and leaves the credential alone', function (): void {
    // The case with no recovery if it went wrong: a refused rotation must not clear
    // or replace what is stored.
    $token = adminToken(roles: ['super_admin']);
    $stale = $this->service->groupVersion('security');

    $this->withToken($token)->withHeaders(['If-Match' => $stale])
        ->putJson('/api/v1/admin/settings/security', ['settings' => ['api_secret_key' => 'first']])
        ->assertOk();

    $this->withToken($token)->withHeaders(['If-Match' => $stale])
        ->putJson('/api/v1/admin/settings/security', ['settings' => ['api_secret_key' => 'second']])
        ->assertStatus(412);

    expect($this->service->get('security.api_secret_key'))->toBe('first');
});

// ── The write itself is still atomic ────────────────────────────────────────

test('a batch that fails partway still leaves the group untouched', function (): void {
    $before = setting('general.site_name');

    updateWith($this, 'general', [
        'comments_enabled' => true,
        'site_name' => ['not' => 'a string'],
    ], $this->service->groupVersion('general'))->assertStatus(422);

    expect(setting('general.site_name'))->toBe($before)
        ->and(setting('general.comments_enabled'))->toBeFalse();
});

test('a successful write returns the new version for the next one', function (): void {
    // So a client can chain writes without re-reading the group each time.
    $response = updateWith($this, 'general', ['comments_enabled' => true], $this->service->groupVersion('general'));

    $response->assertOk();

    expect($response->json('meta.version'))->toBe($this->service->groupVersion('general'));

    updateWith($this, 'general', ['comments_enabled' => false], $response->json('meta.version'))
        ->assertOk();
});
