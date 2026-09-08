<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Models\MfaMethod;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Contracts\MfaEnrolmentStatus;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

/**
 * The identity and security state the administrative user list publishes.
 *
 * Every field here is state the platform already held. What is being tested is the
 * exposure: that it reports the truth, that the boolean means what an administrator
 * will read it to mean, and that nothing behind it can carry a secret.
 *
 * Accounts come from the suite's own `makeAccount`, which sets the guarded
 * account_type deliberately — Pest shares one global function namespace, so a local
 * helper of the same name is a fatal redeclaration rather than a shadow.
 */

function viewingAdmin(): string
{
    return adminToken(roles: ['administrator']);
}

test('the list reports the phone the platform holds, not the lookup digest', function (): void {
    $user = makeAccount(['email' => 'with-phone@example.test']);
    $user->phone = '+962790000000';
    $user->save();

    $rows = collect(
        $this->withToken(viewingAdmin())->getJson('/api/v1/admin/users')->assertOk()->json('data')
    )->keyBy('email');

    $row = $rows['with-phone@example.test'];

    expect($row['phone'])->toBe($user->fresh()->phone)
        // The digest is derived and is never published (ADR 0031).
        ->and($row)->not->toHaveKey('phone_hash');
});

test('verification is reported as both a boolean and a moment', function (): void {
    makeAccount(['email' => 'verified@example.test']);
    makeAccount(['email' => 'unverified@example.test', 'email_verified_at' => null]);

    $rows = collect(
        $this->withToken(viewingAdmin())->getJson('/api/v1/admin/users')->assertOk()->json('data')
    )->keyBy('email');

    expect($rows['verified@example.test']['email_verified'])->toBeTrue()
        ->and($rows['verified@example.test']['email_verified_at'])->toBeString()
        // A client branches on the boolean; an interface renders the moment. Deriving
        // one from the other in every client is how one treats null as verified.
        ->and($rows['unverified@example.test']['email_verified'])->toBeFalse()
        ->and($rows['unverified@example.test']['email_verified_at'])->toBeNull();
});

test('an account with a confirmed second factor is reported as protected', function (): void {
    $user = makeAccount(['email' => 'protected@example.test']);

    MfaMethod::query()->create([
        'user_id' => $user->id,
        'type' => MfaType::TOTP,
        'secret' => 'ABCDEFGHIJKLMNOP',
        'confirmed_at' => now(),
    ]);

    $rows = collect(
        $this->withToken(viewingAdmin())->getJson('/api/v1/admin/users')->assertOk()->json('data')
    )->keyBy('email');

    expect($rows['protected@example.test']['mfa_enrolled'])->toBeTrue();
});

test('an abandoned enrolment does not read as protection', function (): void {
    // The distinction the whole column depends on. An unconfirmed row means somebody
    // started enrolling and stopped; nobody has ever presented that factor.
    $user = makeAccount(['email' => 'half-enrolled@example.test']);

    MfaMethod::query()->create([
        'user_id' => $user->id,
        'type' => MfaType::TOTP,
        'secret' => 'ABCDEFGHIJKLMNOP',
        'confirmed_at' => null,
    ]);

    $rows = collect(
        $this->withToken(viewingAdmin())->getJson('/api/v1/admin/users')->assertOk()->json('data')
    )->keyBy('email');

    expect($rows['half-enrolled@example.test']['mfa_enrolled'])->toBeFalse();
});

test('the response carries no authentication material of any kind', function (): void {
    $user = makeAccount(['email' => 'secret-check@example.test']);

    MfaMethod::query()->create([
        'user_id' => $user->id,
        'type' => MfaType::TOTP,
        'secret' => 'ZZZZTOTPSECRETZZ',
        'confirmed_at' => now(),
    ]);

    $body = $this->withToken(viewingAdmin())->getJson('/api/v1/admin/users')->assertOk()->content();

    expect($body)->not->toContain('ZZZZTOTPSECRETZZ')
        ->and($body)->not->toContain('recovery')
        ->and($body)->not->toContain('password');
});

test('the Core contract answers without the User module importing Auth', function (): void {
    // The point of the contract. The user list is served by a module whose dependency
    // rule forbids importing the one that owns this answer, so the answer arrives
    // through Core — the same direction EffectiveGrants runs in.
    $user = makeAccount(['email' => 'contract@example.test']);

    expect(app(MfaEnrolmentStatus::class)->isEnrolled($user))->toBeFalse();

    MfaMethod::query()->create([
        'user_id' => $user->id,
        'type' => MfaType::TOTP,
        'secret' => 'ABCDEFGHIJKLMNOP',
        'confirmed_at' => now(),
    ]);

    expect(app(MfaEnrolmentStatus::class)->isEnrolled($user->fresh()))->toBeTrue();
});
