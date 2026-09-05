<?php

declare(strict_types=1);

use App\Modules\Core\Services\RateLimitPolicy;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(SettingSeeder::class);
    $this->policy = app(RateLimitPolicy::class);
    $this->settings = app(SettingServiceInterface::class);
});

// ── The group is provisioned ─────────────────────────────────────────────────

test('the seeder provisions the rate_limit group', function (): void {
    $keys = Setting::query()->where('group', 'rate_limit')->orderBy('key')->pluck('key')->all();

    expect($keys)->toBe([
        'authenticated_ip_multiplier',
        'public_read_per_minute',
        'read_per_minute',
        'upload_per_hour',
        'write_per_minute',
    ]);
});

test('every rate_limit setting is internal, not public, and not secret', function (): void {
    // Publishing the ceilings tells an attacker exactly where they are.
    $settings = Setting::query()->where('group', 'rate_limit')->get();

    foreach ($settings as $setting) {
        expect($setting->is_public)->toBeFalse($setting->key.' is public')
            ->and($setting->is_secret)->toBeFalse($setting->key.' is secret');
    }
});

test('the public settings endpoint never exposes a limit', function (): void {
    $encoded = (string) json_encode($this->settings->getPublicSettings());

    expect($encoded)->not->toContain('rate_limit')
        ->and($encoded)->not->toContain('per_minute')
        ->and($encoded)->not->toContain('per_hour');
});

// ── The approved starting limits ─────────────────────────────────────────────

test('the seeded limits are the approved starting values', function (): void {
    expect($this->policy->maxAttempts(RateLimitPolicy::PUBLIC_READ))->toBe(60)
        ->and($this->policy->maxAttempts(RateLimitPolicy::READ))->toBe(120)
        ->and($this->policy->maxAttempts(RateLimitPolicy::WRITE))->toBe(30)
        ->and($this->policy->maxAttempts(RateLimitPolicy::UPLOAD))->toBe(20);
});

test('the windows match the units the limits are expressed in', function (): void {
    expect($this->policy->decayMinutes(RateLimitPolicy::PUBLIC_READ))->toBe(1)
        ->and($this->policy->decayMinutes(RateLimitPolicy::READ))->toBe(1)
        ->and($this->policy->decayMinutes(RateLimitPolicy::WRITE))->toBe(1)
        ->and($this->policy->decayMinutes(RateLimitPolicy::UPLOAD))->toBe(60);
});

test('the auth class shares the anonymous ceiling', function (): void {
    // Those routes are unauthenticated, and LoginThrottle at five attempts a
    // minute always binds first. This only bounds an attacker rotating emails,
    // which LoginThrottle cannot see because its key includes the email.
    expect($this->policy->maxAttempts(RateLimitPolicy::AUTH))
        ->toBe($this->policy->maxAttempts(RateLimitPolicy::PUBLIC_READ));
});

// ── The IP dimension is looser than the identity one ─────────────────────────

test('the per-IP ceiling is looser than the per-user one', function (): void {
    // Shared NAT: an office behind one address must not throttle the second
    // colleague to open the admin.
    foreach ([RateLimitPolicy::READ, RateLimitPolicy::WRITE, RateLimitPolicy::UPLOAD] as $class) {
        expect($this->policy->maxAttemptsForIp($class))
            ->toBeGreaterThan($this->policy->maxAttempts($class), $class.' has no headroom for shared NAT');
    }

    expect($this->policy->ipMultiplier())->toBe(4);
});

// ── Settings drive it, and take effect immediately ───────────────────────────

test('a changed limit takes effect on the next read, not after a TTL', function (): void {
    expect($this->policy->maxAttempts(RateLimitPolicy::WRITE))->toBe(30);

    $this->settings->set('rate_limit', 'write_per_minute', 5);

    expect($this->policy->maxAttempts(RateLimitPolicy::WRITE))->toBe(5);
});

test('a changed multiplier moves every per-IP ceiling', function (): void {
    $this->settings->set('rate_limit', 'authenticated_ip_multiplier', 10);

    expect($this->policy->ipMultiplier())->toBe(10)
        ->and($this->policy->maxAttemptsForIp(RateLimitPolicy::WRITE))->toBe(300);
});

// ── Bad configuration cannot open or close the gate ──────────────────────────

test('a zero or negative limit falls back to the default', function (): void {
    // Zero would reject every request; a negative one is meaningless. Neither is
    // a limit an operator can set by accident and then not understand.
    foreach ([0, -1] as $bad) {
        $this->settings->set('rate_limit', 'write_per_minute', $bad);

        expect($this->policy->maxAttempts(RateLimitPolicy::WRITE))->toBe(30, 'value '.$bad);
    }
});

test('a missing setting falls back to the default', function (): void {
    Setting::query()->where('group', 'rate_limit')->delete();
    app(SettingServiceInterface::class)->clearCache();

    expect($this->policy->maxAttempts(RateLimitPolicy::PUBLIC_READ))->toBe(60)
        ->and($this->policy->maxAttempts(RateLimitPolicy::READ))->toBe(120)
        ->and($this->policy->maxAttempts(RateLimitPolicy::WRITE))->toBe(30)
        ->and($this->policy->maxAttempts(RateLimitPolicy::UPLOAD))->toBe(20)
        ->and($this->policy->ipMultiplier())->toBe(4);
});

test('an unknown class resolves to the anonymous ceiling rather than raising', function (): void {
    // A limiter that raised on an unrecognised class would take the API down for
    // a typo in a route registration.
    expect($this->policy->maxAttempts('no-such-class'))->toBe(60)
        ->and($this->policy->decayMinutes('no-such-class'))->toBe(1);
});

test('the class list is closed and matches the approved taxonomy', function (): void {
    expect(RateLimitPolicy::classes())->toBe(['public-read', 'auth', 'read', 'write', 'upload']);
});
