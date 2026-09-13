<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use App\Modules\User\Enums\AccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
});

// Maintenance mode, as ADR 0018 decided it and as nothing had implemented.
//
// The setting has been configurable since Phase 4 with no reader, so switching the
// platform into maintenance did nothing at all. What is under test is the whole of the
// decided behaviour: 503, the platform envelope, a localized message, and a bypass that
// is a property of the token rather than of the account.

function maintenance(bool $on, bool $bypass = true, ?string $message = null): void
{
    $settings = app(SettingServiceInterface::class);
    $settings->set('general', 'maintenance_mode', $on);
    $settings->set('general', 'maintenance_admin_bypass', $bypass);

    if ($message !== null) {
        $settings->set('general', 'maintenance_message', $message);
    }

    // The service's own invalidation as well as the store's. `set()` already clears
    // the group, and the extra call costs nothing; what it buys is that this helper
    // does not depend on which store the platform cache happens to be using in the
    // environment the suite runs in.
    $settings->clearCache('general');
    Cache::flush();
}

test('the platform serves normally while maintenance is off', function (): void {
    maintenance(false);

    $this->getJson('/api/v1/health')->assertOk();
});

test('a closed platform answers 503 with the envelope rather than an HTML page', function (): void {
    maintenance(true);

    $response = $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'MAINTENANCE_MODE');

    // Never a framework page: a client that asked for JSON is answered in JSON even
    // when the answer is that there is no service (ADR 0018, ADR 0031).
    expect($response->headers->get('content-type'))->toContain('application/json');
});

test('the message an operator wrote is the message a caller reads', function (): void {
    maintenance(true, message: 'We are upgrading the database and will be back at 04:00.');

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('error.message', 'We are upgrading the database and will be back at 04:00.');
});

test('with no message configured the platform still says something', function (): void {
    maintenance(true, message: '');

    $response = $this->getJson('/api/v1/health')->assertStatus(503);

    // An empty setting is not a reason to answer with an empty sentence.
    expect($response->json('error.message'))->not->toBe('');
});

test('an administrative token works through a closed platform', function (): void {
    maintenance(true, bypass: true);

    $token = adminToken(['admin:access']);

    // The whole point of the bypass: the platform can be repaired through its own
    // interface rather than only from a shell.
    $this->withToken($token)->getJson('/api/v1/health')->assertOk();
});

test('the bypass is the ability the token carries, not the type of the account', function (): void {
    maintenance(true, bypass: true);

    $admin = makeAccount([
        'name' => 'An Administrator',
        'email' => 'administrator@example.test',
        'account_type' => AccountType::ADMIN,
    ]);

    // An administrator calling with an ordinary session is not administering anything
    // at that moment, and the perimeter of ADR 0012 is the ability rather than the row.
    $token = $admin->createToken('ordinary-session', ['user:access'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/health')->assertStatus(503);
});

test('with the bypass switched off nobody gets in, including an administrator', function (): void {
    maintenance(true, bypass: false);

    $token = adminToken(['admin:access']);

    // Exactly what the setting says. The way back is the console, and the setting's
    // help text says so rather than leaving it to be discovered.
    $this->withToken($token)->getJson('/api/v1/health')->assertStatus(503);
});

test('an administrative endpoint is closed too, not only the public surface', function (): void {
    maintenance(true, bypass: false);

    $token = adminToken(['admin:access']);

    $this->withToken($token)->getJson('/api/v1/admin/languages')->assertStatus(503);
});

test('the refusal is written in the language the caller asked for', function (): void {
    maintenance(true, message: 'English maintenance message.');

    Setting::query()
        ->where('group', 'general')
        ->where('key', 'maintenance_message')
        ->firstOrFail()
        ->setLocalizedValue('ar', 'رسالة الصيانة بالعربية.');
    Cache::flush();

    $this->withHeaders(['X-Locale' => 'ar'])
        ->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('error.message', 'رسالة الصيانة بالعربية.');
});
