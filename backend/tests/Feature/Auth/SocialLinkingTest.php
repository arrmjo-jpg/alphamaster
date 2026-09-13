<?php

declare(strict_types=1);

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Models\SocialIdentity;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGoogle;

/*
 * A signed-in user linking and unlinking social identities (ADR 0050 §5).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);
    FakeGoogle::configure();
});

/**
 * Link Google to the account behind a token, with the provider vouching for this subject.
 */
function linkGoogle(mixed $test, string $token, string $subject, string $email = 'linked@example.test')
{
    $flow = FakeGoogle::authorize($test, '/api/v1/auth/social/google/link/authorize', $token);
    FakeGoogle::respondWith(FakeGoogle::idToken(FakeGoogle::claims($flow['nonce'], ['sub' => $subject, 'email' => $email])));

    return $test->withToken($token)->postJson('/api/v1/auth/social/google/link', [
        'code' => 'link-code',
        'state' => $flow['state'],
        'code_verifier' => $flow['verifier'],
    ]);
}

// ── Who may link ─────────────────────────────────────────────────────────────

test('linking requires a signed-in user', function (): void {
    $body = [
        'redirect_uri' => FakeGoogle::REDIRECT_URI,
        'code_challenge' => FakeGoogle::challenge(FakeGoogle::verifier()),
        'code_challenge_method' => 'S256',
    ];

    $this->postJson('/api/v1/auth/social/google/link/authorize', $body)->assertStatus(401);
    $this->getJson('/api/v1/auth/social/identities')->assertStatus(401);
});

test('an administrator token reaches none of the linking routes', function (): void {
    $token = adminToken();

    $this->withToken($token)->postJson('/api/v1/auth/social/google/link/authorize', [
        'redirect_uri' => FakeGoogle::REDIRECT_URI,
        'code_challenge' => FakeGoogle::challenge(FakeGoogle::verifier()),
        'code_challenge_method' => 'S256',
    ])->assertStatus(403);

    $this->withToken($token)->getJson('/api/v1/auth/social/identities')->assertStatus(403);

    expect(SocialIdentity::query()->count())->toBe(0);
});

// ── Linking ──────────────────────────────────────────────────────────────────

test('a user links Google to their account, and nothing about their address changes', function (): void {
    ['user' => $user, 'token' => $token] = regularWithToken($this, 'links-google@example.test');
    DB::table('users')->where('id', $user->id)->update(['email_verified_at' => null]);

    linkGoogle($this, $token, 'linking-subject', 'a-different-address@example.test')
        ->assertStatus(201)
        ->assertJsonPath('data.provider', 'google');

    expect(SocialIdentity::query()->where('user_id', $user->id)->linked()->pluck('provider_subject')->all())->toBe(['linking-subject'])
        ->and($user->fresh()->email)->toBe('links-google@example.test')
        ->and(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->toBeNull()
        ->and(DB::table('audit_records')->where('action', AuditAction::ACCOUNT_SOCIAL_LINKED)->count())->toBe(1);

    $this->withToken($token)->getJson('/api/v1/auth/social/identities')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.provider', 'google');
});

test('an identity that belongs to another account cannot be linked', function (): void {
    $owner = makeAccount(['email' => 'first-owner@example.test']);
    SocialIdentity::query()->create([
        'user_id' => $owner->id,
        'provider' => 'google',
        'provider_subject' => 'taken-subject',
        'linked_at' => now(),
    ]);

    ['token' => $token] = regularWithToken($this, 'second-user@example.test');

    linkGoogle($this, $token, 'taken-subject')
        ->assertStatus(409)->assertJsonPath('error.code', 'SOCIAL_IDENTITY_IN_USE');
});

test('an account cannot link a second Google identity', function (): void {
    ['token' => $token] = regularWithToken($this, 'two-googles@example.test');

    linkGoogle($this, $token, 'first-google')->assertStatus(201);

    linkGoogle($this, $token, 'second-google')
        ->assertStatus(409)->assertJsonPath('error.code', 'SOCIAL_PROVIDER_ALREADY_LINKED');
});

test('re-linking an identity the account unlinked reuses the same row', function (): void {
    ['user' => $user, 'token' => $token] = regularWithToken($this, 'relinks@example.test');

    $first = linkGoogle($this, $token, 'returning-google')->assertStatus(201)->json('data.id');

    $this->withToken($token)->deleteJson('/api/v1/auth/social/identities/'.$first)->assertNoContent();

    $again = linkGoogle($this, $token, 'returning-google')->assertStatus(201)->json('data.id');

    expect($again)->toBe($first)
        ->and(SocialIdentity::query()->where('provider_subject', 'returning-google')->count())->toBe(1)
        ->and(SocialIdentity::query()->whereKey($first)->value('user_id'))->toBe($user->id);
});

test('a state issued for signing in cannot link, and one issued to another account cannot either', function (): void {
    ['token' => $token] = regularWithToken($this, 'intent-owner@example.test');
    ['token' => $otherToken] = regularWithToken($this, 'intent-other@example.test');

    $signIn = FakeGoogle::authorize($this);
    Http::fake();

    resetClient($this);

    $this->withToken($token)->postJson('/api/v1/auth/social/google/link', [
        'code' => 'code',
        'state' => $signIn['state'],
        'code_verifier' => $signIn['verifier'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'SOCIAL_STATE_INVALID');

    resetClient($this);

    $linkForOwner = FakeGoogle::authorize($this, '/api/v1/auth/social/google/link/authorize', $token);

    resetClient($this);

    $this->withToken($otherToken)->postJson('/api/v1/auth/social/google/link', [
        'code' => 'code',
        'state' => $linkForOwner['state'],
        'code_verifier' => $linkForOwner['verifier'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'SOCIAL_STATE_INVALID');

    Http::assertNothingSent();
    expect(SocialIdentity::query()->count())->toBe(0);
});

// ── Unlinking ────────────────────────────────────────────────────────────────

test('unlinking keeps the row and removes the identity from the account’s list', function (): void {
    ['token' => $token] = regularWithToken($this, 'unlinks@example.test');

    $id = linkGoogle($this, $token, 'unlinked-google')->assertStatus(201)->json('data.id');

    $this->withToken($token)->deleteJson('/api/v1/auth/social/identities/'.$id)->assertNoContent();

    $this->withToken($token)->getJson('/api/v1/auth/social/identities')->assertOk()->assertJsonCount(0, 'data');

    expect(SocialIdentity::query()->whereKey($id)->whereNotNull('unlinked_at')->exists())->toBeTrue();
});

test('the only way to sign in to an account cannot be unlinked', function (): void {
    ['user' => $user, 'token' => $token] = regularWithToken($this, 'only-way-in@example.test');

    $id = linkGoogle($this, $token, 'only-google')->assertStatus(201)->json('data.id');

    DB::table('users')->where('id', $user->id)->update(['password' => null]);

    $this->withToken($token)->deleteJson('/api/v1/auth/social/identities/'.$id)
        ->assertStatus(409)->assertJsonPath('error.code', 'LAST_SIGN_IN_METHOD');

    expect(SocialIdentity::query()->whereKey($id)->linked()->exists())->toBeTrue();
});

test('an identity that is not the account’s own cannot be unlinked through it', function (): void {
    $owner = makeAccount(['email' => 'unlink-owner@example.test']);
    $identity = SocialIdentity::query()->create([
        'user_id' => $owner->id,
        'provider' => 'google',
        'provider_subject' => 'owners-subject',
        'linked_at' => now(),
    ]);

    ['token' => $token] = regularWithToken($this, 'unlink-intruder@example.test');

    $this->withToken($token)->deleteJson('/api/v1/auth/social/identities/'.$identity->id)
        ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

    expect($identity->fresh()->isLinked())->toBeTrue();
});

test('linking never promotes, and a linked account stays a user', function (): void {
    ['user' => $user, 'token' => $token] = regularWithToken($this, 'stays-user@example.test');

    linkGoogle($this, $token, 'stays-user-google')->assertStatus(201);

    expect(User::query()->findOrFail($user->id)->isAdmin())->toBeFalse();
});
