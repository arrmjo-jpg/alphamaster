<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where rate-limiter counters outlive a test.
    Cache::flush();

    $this->seed(SettingSeeder::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makeUser(array $attributes = []): User
{
    return User::create(array_merge([
        'name' => 'Test Person',
        'email' => 'person@example.com',
        'password' => 'correct-horse-battery',
        'is_admin' => false,
        'is_active' => true,
    ], $attributes));
}

test('a regular user can sign in and receives a user:access token', function (): void {
    makeUser();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'person@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.abilities', [TokenAbility::USER_ACCESS->value]);

    $token = PersonalAccessToken::findToken($response->json('data.token'));

    expect($token)->not->toBeNull()
        ->and($token->can(TokenAbility::USER_ACCESS->value))->toBeTrue()
        ->and($token->can(TokenAbility::ADMIN_ACCESS->value))->toBeFalse();
});

test('an administrator receives an admin:access token only after enrolling MFA', function (): void {
    makeUser(['email' => 'boss@example.com', 'is_admin' => true]);

    $result = signInAdminWithMfa($this, 'boss@example.com', 'correct-horse-battery');
    $token = PersonalAccessToken::findToken($result['token']);

    expect($token)->not->toBeNull()
        ->and($token->can(TokenAbility::ADMIN_ACCESS->value))->toBeTrue();
});

test('a token carries exactly one ability, never both', function (): void {
    makeUser(['email' => 'boss2@example.com', 'is_admin' => true]);

    $result = signInAdminWithMfa($this, 'boss2@example.com', 'correct-horse-battery');
    $token = PersonalAccessToken::findToken($result['token']);

    expect($token->abilities)->toBe([TokenAbility::ADMIN_ACCESS->value])
        ->and($token->abilities)->toHaveCount(1)
        // The wildcard would defeat the whole ability layer.
        ->and($token->abilities)->not->toContain('*');
});

test('a regular user token is refused at the admin perimeter', function (): void {
    makeUser(['email' => 'plain@example.com']);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'plain@example.com',
        'password' => 'correct-horse-battery',
    ])->json('data.token');

    // Rejected at the ability layer, before any route or policy logic runs.
    $this->withToken($token)
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403);
});

test('an administrator token is accepted at the admin perimeter', function (): void {
    makeUser(['email' => 'boss3@example.com', 'is_admin' => true]);

    $token = signInAdminWithMfa($this, 'boss3@example.com', 'correct-horse-battery')['token'];

    $this->withToken($token)
        ->getJson('/api/v1/admin/settings')
        ->assertOk();
});

test('an is_admin user whose token lacks the ability is still refused', function (): void {
    // Proves the perimeter checks the token, not merely the user record.
    $admin = makeUser(['email' => 'boss4@example.com', 'is_admin' => true]);
    $downgraded = $admin->createToken('downgraded', [TokenAbility::USER_ACCESS->value])->plainTextToken;

    $this->withToken($downgraded)
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403);
});

test('invalid credentials are rejected without revealing which half was wrong', function (): void {
    makeUser(['email' => 'real@example.com']);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => 'not-the-password',
    ]);

    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'email' => 'ghost@example.com',
        'password' => 'not-the-password',
    ]);

    $wrongPassword->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    $unknownEmail->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    expect($wrongPassword->json('error.message'))->toBe($unknownEmail->json('error.message'));
});

test('a suspended account cannot obtain a token even with correct credentials', function (): void {
    makeUser(['email' => 'suspended@example.com', 'is_active' => false]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'suspended@example.com',
        'password' => 'correct-horse-battery',
    ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('the password is never echoed back in any response', function (): void {
    makeUser(['email' => 'echo@example.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'echo@example.com',
        'password' => 'correct-horse-battery',
    ]);

    expect($response->getContent())->not->toContain('correct-horse-battery');
});

test('logout revokes the presented token and nothing else', function (): void {
    $user = makeUser(['email' => 'bye@example.com']);
    $keep = $user->createToken('other-device', [TokenAbility::USER_ACCESS->value])->plainTextToken;

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'bye@example.com',
        'password' => 'correct-horse-battery',
    ])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    // The guard caches the resolved user for the lifetime of the application
    // instance, which a test reuses across requests but production never does.
    $this->app['auth']->forgetGuards();

    expect(PersonalAccessToken::findToken($token))->toBeNull()
        ->and(PersonalAccessToken::findToken($keep))->not->toBeNull();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('me returns the identity and the abilities actually on the token', function (): void {
    makeUser(['email' => 'who@example.com', 'is_admin' => true]);

    $token = signInAdminWithMfa($this, 'who@example.com', 'correct-horse-battery')['token'];

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'who@example.com')
        ->assertJsonPath('data.is_admin', true)
        ->assertJsonPath('data.abilities', [TokenAbility::ADMIN_ACCESS->value]);
});

test('me is unreachable without a token', function (): void {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('a user suspended after signing in loses access on the next request', function (): void {
    $user = makeUser(['email' => 'later@example.com']);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'later@example.com',
        'password' => 'correct-horse-battery',
    ])->json('data.token');

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    // As above: drop the cached guard resolution so the next request re-reads the user.
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
});

test('the users ULID primary key drives the Sanctum token relationship', function (): void {
    $user = makeUser(['email' => 'ulid@example.com']);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'ulid@example.com',
        'password' => 'correct-horse-battery',
    ])->json('data.token');

    $model = PersonalAccessToken::findToken($token);

    expect($user->id)->toBeString()
        ->and(strlen($user->id))->toBe(26)
        ->and(Str::isUlid($user->id))->toBeTrue()
        // The morph stores the ULID verbatim, not a coerced integer.
        ->and($model->tokenable_id)->toBe($user->id)
        ->and($model->tokenable_type)->toBe(User::class)
        ->and($model->tokenable->is($user))->toBeTrue();
});
