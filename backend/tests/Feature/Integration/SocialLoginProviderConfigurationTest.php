<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Configuring a social login provider through the existing Integration admin endpoint
 * (ADR 0050 §10, ADR 0038): its required configuration is a client id and a client
 * secret, and a provider missing either cannot be switched on, whatever order the fields
 * are saved in.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    $this->token = adminToken(roles: ['super_admin']);

    $this->google = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::SOCIAL_LOGIN)
        ->where('driver', 'google')
        ->firstOrFail();
});

function putGoogleProvider(mixed $test, array $body)
{
    return $test->withToken($test->token)
        ->putJson('/api/v1/admin/integrations/providers/'.$test->google->id, $body);
}

test('the Google provider ships switched off and unconfigured', function (): void {
    expect($this->google->is_active)->toBeFalse()
        ->and($this->google->hasCredentials())->toBeFalse()
        ->and($this->google->settings['client_id'] ?? null)->toBe('');
});

test('switching on a provider with no client id and no secret is refused, naming what is missing', function (): void {
    putGoogleProvider($this, ['is_active' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PROVIDER_CONFIGURATION_INCOMPLETE')
        ->assertJsonPath('error.details.missing', ['client_id', 'client_secret']);

    expect($this->google->refresh()->is_active)->toBeFalse();
});

test('a client id alone, or a secret alone, is not enough to switch it on', function (): void {
    putGoogleProvider($this, ['is_active' => true, 'settings' => ['client_id' => 'id.apps.googleusercontent.com']])
        ->assertStatus(422)
        ->assertJsonPath('error.details.missing', ['client_secret']);

    putGoogleProvider($this, ['is_active' => true, 'credentials' => ['client_secret' => 'a-client-secret']])
        ->assertStatus(422)
        ->assertJsonPath('error.details.missing', ['client_id']);

    expect($this->google->refresh()->is_active)->toBeFalse()
        ->and($this->google->hasCredentials())->toBeFalse();
});

test('partial configuration may be stored while the provider stays off', function (): void {
    putGoogleProvider($this, ['credentials' => ['client_secret' => 'stored-first']])->assertOk();
    putGoogleProvider($this, ['settings' => ['client_id' => 'id.apps.googleusercontent.com']])->assertOk();

    expect($this->google->refresh()->is_active)->toBeFalse()
        ->and($this->google->getCredentials()['client_secret'])->toBe('stored-first');
});

test('a fully configured provider is switched on, in one request or after saving in any order', function (): void {
    putGoogleProvider($this, [
        'settings' => ['client_id' => 'id.apps.googleusercontent.com'],
        'credentials' => ['client_secret' => 'one-request-secret'],
        'is_active' => true,
    ])->assertOk()->assertJsonPath('data.is_active', true);

    putGoogleProvider($this, ['is_active' => false])->assertOk();

    // Stored earlier, switched on later: the order fields arrived in does not matter.
    putGoogleProvider($this, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
});

test('an active provider cannot be left without its secret or its client id', function (): void {
    putGoogleProvider($this, [
        'settings' => ['client_id' => 'id.apps.googleusercontent.com'],
        'credentials' => ['client_secret' => 'kept-secret'],
        'is_active' => true,
    ])->assertOk();

    putGoogleProvider($this, ['credentials' => null])
        ->assertStatus(422)
        ->assertJsonPath('error.details.missing', ['client_secret']);

    putGoogleProvider($this, ['settings' => ['client_id' => '']])
        ->assertStatus(422)
        ->assertJsonPath('error.details.missing', ['client_id']);

    $fresh = $this->google->refresh();

    expect($fresh->is_active)->toBeTrue()
        ->and($fresh->getCredentials()['client_secret'])->toBe('kept-secret')
        ->and($fresh->settings['client_id'])->toBe('id.apps.googleusercontent.com');
});

test('a provider configured through the admin API is the one sign-in offers', function (): void {
    app(SettingServiceInterface::class)->set('auth', 'social_login_enabled', true);
    app(SettingServiceInterface::class)->set('auth', 'social_redirect_uris', ['https://client.example.test/auth/callback']);
    Cache::flush();

    $this->getJson('/api/v1/auth/social/providers')->assertJsonPath('data', []);

    putGoogleProvider($this, [
        'settings' => ['client_id' => 'id.apps.googleusercontent.com'],
        'credentials' => ['client_secret' => 'offered-secret'],
        'is_active' => true,
    ])->assertOk();

    resetClient($this);

    $this->getJson('/api/v1/auth/social/providers')
        ->assertOk()
        ->assertJsonPath('data', [['key' => 'google', 'label' => 'Google']]);
});

test('neither the secret nor its ciphertext is ever read back', function (): void {
    $response = putGoogleProvider($this, [
        'settings' => ['client_id' => 'id.apps.googleusercontent.com'],
        'credentials' => ['client_secret' => 'never-read-back'],
        'is_active' => true,
    ])->assertOk()->assertJsonPath('data.has_credentials', true);

    $ciphertext = (string) DB::table('integration_providers')->where('id', $this->google->id)->value('credentials');

    $listing = $this->withToken($this->token)->getJson('/api/v1/admin/integrations/providers')->assertOk()->content();

    foreach ([$response->content(), $listing] as $body) {
        expect($body)->not->toContain('never-read-back')
            ->and($body)->not->toContain($ciphertext);
    }
});

test('a refusal carries field names only, never a submitted value', function (): void {
    $body = putGoogleProvider($this, [
        'settings' => ['client_id' => 'submitted-client-id-value'],
        'is_active' => true,
    ])->assertStatus(422)->content();

    expect($body)->not->toContain('submitted-client-id-value');
});
