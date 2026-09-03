<?php

declare(strict_types=1);

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Enums\SettingType;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SettingSeeder::class);
    $this->service = app(SettingServiceInterface::class);
    $this->token = adminToken();
});

/**
 * Fetch the raw stored column, bypassing every accessor.
 */
function storedValue(string $group, string $key): ?string
{
    return DB::table('settings')->where('group', $group)->where('key', $key)->value('value');
}

// ─── Null semantics ───────────────────────────────────────────────────────────

test('a freshly seeded secret is unset rather than randomly generated', function (): void {
    expect(storedValue('security', 'api_secret_key'))->toBeNull()
        ->and($this->service->get('security.api_secret_key'))->toBeNull();
});

test('re-running the seeder never rotates a provisioned secret nor reverts customisations', function (): void {
    $this->service->set('security', 'api_secret_key', 'operator-provisioned');
    $this->service->set('general', 'site_name', 'Operator Brand');

    $ciphertextBefore = storedValue('security', 'api_secret_key');

    $this->seed(SettingSeeder::class);
    $this->seed(SettingSeeder::class);

    expect(storedValue('security', 'api_secret_key'))->toBe($ciphertextBefore)
        ->and($this->service->get('security.api_secret_key'))->toBe('operator-provisioned')
        ->and($this->service->get('general.site_name'))->toBe('Operator Brand')
        ->and(Setting::query()->where('group', 'general')->where('key', 'site_name')->count())->toBe(1);
});

test('writing null stores SQL NULL instead of an empty string', function (): void {
    $this->service->set('general', 'site_description', null);

    expect(storedValue('general', 'site_description'))->toBeNull()
        ->and($this->service->get('general.site_description'))->toBeNull();
});

test('null is distinguishable from a default for a provisioned key', function (): void {
    $this->service->set('general', 'site_description', null);

    // Provisioned but unset resolves to null, never to the caller's fallback.
    expect($this->service->get('general.site_description', 'FALLBACK'))->toBeNull()
        // A key that does not exist at all is what the fallback is for.
        ->and($this->service->get('general.not_provisioned', 'FALLBACK'))->toBe('FALLBACK');
});

test('an unset typed setting round-trips as null rather than a coerced zero', function (): void {
    $this->service->set('auth', 'session_lifetime', null);

    expect($this->service->get('auth.session_lifetime'))->toBeNull()
        ->and(storedValue('auth', 'session_lifetime'))->toBeNull();
});

test('serializeValue never coerces null or arrays implicitly', function (): void {
    expect(Setting::serializeValue(null, SettingType::STRING))->toBeNull();

    expect(fn () => Setting::serializeValue(['a' => 1], SettingType::STRING))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => Setting::serializeValue(true, SettingType::STRING))
        ->toThrow(InvalidArgumentException::class);
});

// ─── Secret lifecycle: omitted / mask / null / new value ──────────────────────

test('secret lifecycle: an omitted secret is left untouched', function (): void {
    $this->service->set('security', 'api_secret_key', 'original-secret');
    $ciphertext = storedValue('security', 'api_secret_key');

    $this->withToken($this->token)->putJson('/api/v1/admin/settings/security', [
        'settings' => ['max_login_attempts' => 9], // api_secret_key omitted entirely
    ])->assertOk();

    expect(storedValue('security', 'api_secret_key'))->toBe($ciphertext)
        ->and($this->service->get('security.api_secret_key'))->toBe('original-secret')
        ->and($this->service->get('security.max_login_attempts'))->toBe(9);
});

test('secret lifecycle: submitting the mask preserves the stored secret', function (): void {
    $this->service->set('security', 'api_secret_key', 'original-secret');
    $ciphertext = storedValue('security', 'api_secret_key');

    $response = $this->withToken($this->token)->putJson('/api/v1/admin/settings/security', [
        'settings' => [
            'max_login_attempts' => 10,
            'api_secret_key' => Setting::SECRET_MASK,
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.updated.api_secret_key', Setting::SECRET_MASK);

    expect(storedValue('security', 'api_secret_key'))->toBe($ciphertext)
        ->and($this->service->get('security.api_secret_key'))->toBe('original-secret')
        ->and($this->service->get('security.max_login_attempts'))->toBe(10);
});

test('secret lifecycle: submitting null clears the secret', function (): void {
    $this->service->set('security', 'api_secret_key', 'original-secret');

    $response = $this->withToken($this->token)->putJson('/api/v1/admin/settings/security', [
        'settings' => ['api_secret_key' => null],
    ]);

    $response->assertOk()
        // A cleared secret reports null, not a mask that would imply a value exists.
        ->assertJsonPath('data.updated.api_secret_key', null);

    expect(storedValue('security', 'api_secret_key'))->toBeNull()
        ->and($this->service->get('security.api_secret_key'))->toBeNull();
});

test('secret lifecycle: submitting the mask for a cleared secret leaves it cleared', function (): void {
    $this->withToken($this->token)->putJson('/api/v1/admin/settings/security', [
        'settings' => ['api_secret_key' => Setting::SECRET_MASK],
    ])->assertOk()->assertJsonPath('data.updated.api_secret_key', null);

    expect(storedValue('security', 'api_secret_key'))->toBeNull();
});

test('secret lifecycle: submitting a new value encrypts it and reports only the mask', function (): void {
    $response = $this->withToken($this->token)->putJson('/api/v1/admin/settings/security', [
        'settings' => ['api_secret_key' => 'brand-new-secret'],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.updated.api_secret_key', Setting::SECRET_MASK);

    $ciphertext = storedValue('security', 'api_secret_key');

    expect($ciphertext)->toBeString()
        ->and($ciphertext)->not->toContain('brand-new-secret')
        ->and($response->getContent())->not->toContain('brand-new-secret')
        ->and($this->service->get('security.api_secret_key'))->toBe('brand-new-secret');
});

test('secret lifecycle: replacing a secret produces different ciphertext and the new plaintext', function (): void {
    $this->service->set('security', 'api_secret_key', 'first-secret');
    $first = storedValue('security', 'api_secret_key');

    $this->service->set('security', 'api_secret_key', 'second-secret');
    $second = storedValue('security', 'api_secret_key');

    expect($second)->not->toBe($first)
        ->and($this->service->get('security.api_secret_key'))->toBe('second-secret');
});

test('the admin listing renders a cleared secret as null and a set secret as the mask', function (): void {
    $cleared = collect($this->withToken($this->token)->getJson('/api/v1/admin/settings/security')->json('data'))
        ->firstWhere('key', 'api_secret_key');

    expect($cleared['value'])->toBeNull();

    $this->service->set('security', 'api_secret_key', 'now-set');

    $set = collect($this->withToken($this->token)->getJson('/api/v1/admin/settings/security')->json('data'))
        ->firstWhere('key', 'api_secret_key');

    expect($set['value'])->toBe(Setting::SECRET_MASK);
});
