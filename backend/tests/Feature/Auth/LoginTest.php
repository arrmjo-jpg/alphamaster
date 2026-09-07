<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
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
    $this->seed(AdminPermissionSeeder::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makeUser(array $attributes = []): User
{
    return makeAccount(array_merge([
        'name' => 'Test Person',
        'email' => 'person@example.com',
        'password' => 'correct-horse-battery',
        'account_type' => AccountType::USER,
        'is_active' => true,
    ], $attributes));
}

test('a regular user can sign in and receives a user:access token', function (): void {
    makeUser();

    $response = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'person@example.com',
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
    makeUser(['email' => 'boss@example.com', 'account_type' => AccountType::ADMIN]);

    $result = signInAdminWithMfa($this, 'boss@example.com', 'correct-horse-battery');
    $token = PersonalAccessToken::findToken($result['token']);

    expect($token)->not->toBeNull()
        ->and($token->can(TokenAbility::ADMIN_ACCESS->value))->toBeTrue();
});

test('a token carries exactly one ability, never both', function (): void {
    makeUser(['email' => 'boss2@example.com', 'account_type' => AccountType::ADMIN]);

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
        'identifier' => 'plain@example.com',
        'password' => 'correct-horse-battery',
    ])->json('data.token');

    // Rejected at the ability layer, before any route or policy logic runs.
    $this->withToken($token)
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403);
});

test('an administrator token is accepted at the admin perimeter', function (): void {
    $admin = makeUser(['email' => 'boss3@example.com', 'account_type' => AccountType::ADMIN]);

    // The probe endpoint requires a permission as well as the perimeter, so the
    // admin holds one; without it a 403 here would not distinguish the two stages.
    app(AdminRbacContract::class)->syncRoles($admin, ['administrator']);

    $token = signInAdminWithMfa($this, 'boss3@example.com', 'correct-horse-battery')['token'];

    $this->withToken($token)
        ->getJson('/api/v1/admin/settings')
        ->assertOk();
});

test('an admin account whose token lacks the ability is still refused', function (): void {
    // Proves the perimeter checks the token, not merely the user record.
    $admin = makeUser(['email' => 'boss4@example.com', 'account_type' => AccountType::ADMIN]);
    $downgraded = $admin->createToken('downgraded', [TokenAbility::USER_ACCESS->value])->plainTextToken;

    $this->withToken($downgraded)
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403);
});

test('invalid credentials are rejected without revealing which half was wrong', function (): void {
    makeUser(['email' => 'real@example.com']);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'real@example.com',
        'password' => 'not-the-password',
    ]);

    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'ghost@example.com',
        'password' => 'not-the-password',
    ]);

    $wrongPassword->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    $unknownEmail->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    expect($wrongPassword->json('error.message'))->toBe($unknownEmail->json('error.message'));
});

test('a suspended account cannot obtain a token even with correct credentials', function (): void {
    makeUser(['email' => 'suspended@example.com', 'is_active' => false]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'suspended@example.com',
        'password' => 'correct-horse-battery',
    ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('the password is never echoed back in any response', function (): void {
    makeUser(['email' => 'echo@example.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'echo@example.com',
        'password' => 'correct-horse-battery',
    ]);

    expect($response->getContent())->not->toContain('correct-horse-battery');
});

test('logout revokes the presented token and nothing else', function (): void {
    $user = makeUser(['email' => 'bye@example.com']);
    $keep = $user->createToken('other-device', [TokenAbility::USER_ACCESS->value])->plainTextToken;

    $token = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'bye@example.com',
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
    makeUser(['email' => 'who@example.com', 'account_type' => AccountType::ADMIN]);

    $token = signInAdminWithMfa($this, 'who@example.com', 'correct-horse-battery')['token'];

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'who@example.com')
        ->assertJsonPath('data.account_type', 'admin')
        ->assertJsonPath('data.abilities', [TokenAbility::ADMIN_ACCESS->value]);
});

test('me is unreachable without a token', function (): void {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('a user suspended after signing in loses access on the next request', function (): void {
    $user = makeUser(['email' => 'later@example.com']);

    $token = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'later@example.com',
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
        'identifier' => 'ulid@example.com',
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

// ── Signing in with a phone number ───────────────────────────────────────────

test('an account can sign in with its phone number', function (): void {
    makeUser(['email' => 'byphone@example.com', 'phone' => '+962790000000']);

    $response = $this->postJson('/api/v1/auth/login', [
        'identifier' => '+962790000000',
        'password' => 'correct-horse-battery',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.abilities', [TokenAbility::USER_ACCESS->value]);
});

test('the number may be written any of the ways a person writes it', function (string $written): void {
    makeUser(['email' => 'anyform@example.com', 'phone' => '+962790000000']);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => $written,
        'password' => 'correct-horse-battery',
    ])->assertOk();
})->with([
    'canonical' => '+962790000000',
    'spaced' => '+962 79 000 0000',
    'hyphenated' => '+962-79-000-0000',
    'parenthesised' => '+962 (79) 000-0000',
]);

test('signing in by phone and by email reach the same account', function (): void {
    $user = makeUser(['email' => 'same@example.com', 'phone' => '+962790000000']);

    $byEmail = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'same@example.com',
        'password' => 'correct-horse-battery',
    ])->json('data.token');

    $byPhone = $this->postJson('/api/v1/auth/login', [
        'identifier' => '+962790000000',
        'password' => 'correct-horse-battery',
    ])->json('data.token');

    expect(PersonalAccessToken::findToken($byEmail)->tokenable_id)->toBe($user->id)
        ->and(PersonalAccessToken::findToken($byPhone)->tokenable_id)->toBe($user->id);
});

test('an account with no phone number cannot be reached by one', function (): void {
    makeUser(['email' => 'nophone@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => '+962790000000',
        'password' => 'correct-horse-battery',
    ])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

test('a suspended account is refused by phone exactly as by email', function (): void {
    makeUser(['email' => 'suspphone@example.com', 'phone' => '+962790000000', 'is_active' => false]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => '+962790000000',
        'password' => 'correct-horse-battery',
    ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

// ── The refusal says nothing about the identifier ────────────────────────────

test('every unusable identifier is refused identically', function (): void {
    makeUser(['email' => 'known@example.com', 'phone' => '+962790000000']);

    // A wrong password, an unknown email, an unknown number, and a value that is
    // neither. If any of these differed, the endpoint would be answering "that
    // identifier exists" or "that identifier was well-formed" to a caller who has
    // proven nothing.
    $responses = collect([
        'wrong password' => 'known@example.com',
        'unknown email' => 'ghost@example.com',
        'unknown number' => '+962790000009',
        'not an identifier at all' => 'nonsense',
        'a malformed number' => '0790000000',
    ])->map(fn (string $identifier) => $this->postJson('/api/v1/auth/login', [
        'identifier' => $identifier,
        'password' => 'not-the-password',
    ]));

    foreach ($responses as $label => $response) {
        expect($response->status())->toBe(401, $label)
            ->and($response->json('error.code'))->toBe('INVALID_CREDENTIALS', $label);
    }

    // Same sentence too, not merely the same code.
    expect($responses->map(fn ($r) => $r->json('error.message'))->unique())->toHaveCount(1);
});

test('a malformed identifier is a failed sign-in rather than a validation error', function (): void {
    // 422 would tell an unauthenticated caller that its input was the wrong shape,
    // which is a fact about the platform's accounts it has not earned.
    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'not-an-email-and-not-a-number',
        'password' => 'whatever',
    ])->assertStatus(401);
});

// ── The throttle counts one account, not one spelling ────────────────────────

test('failures against one number accumulate however the number is written', function (): void {
    makeUser(['email' => 'throttled@example.com', 'phone' => '+962790000000']);

    $written = ['+962790000000', '+962 79 000 0000', '+962-79-000-0000', '+962 (79) 000-0000'];

    $remaining = [];

    foreach ($written as $identifier) {
        $remaining[] = $this->postJson('/api/v1/auth/login', [
            'identifier' => $identifier,
            'password' => 'not-the-password',
        ])->json('error.details.attempts_remaining');
    }

    // Strictly decreasing. If the limiter keyed on the typed string, each spelling
    // would open a fresh allowance and these would all be equal — which is a rate
    // limit an attacker bypasses with the space bar.
    expect($remaining)->toBe([$remaining[0], $remaining[0] - 1, $remaining[0] - 2, $remaining[0] - 3]);
});

// ── Nothing about MFA moved ──────────────────────────────────────────────────

test('an administrator signing in by phone still lands on MFA enrolment', function (): void {
    makeUser([
        'email' => 'adminphone@example.com',
        'phone' => '+962790000001',
        'account_type' => AccountType::ADMIN,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => '+962 79 000 0001',
        'password' => 'correct-horse-battery',
    ])
        ->assertOk()
        ->assertJsonPath('data.mfa_setup_required', true)
        ->assertJsonPath('data.abilities', [TokenAbility::MFA_ENROL->value]);
});
