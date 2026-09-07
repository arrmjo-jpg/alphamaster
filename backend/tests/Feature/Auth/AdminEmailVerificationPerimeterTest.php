<?php

declare(strict_types=1);

use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Exceptions\UnverifiedAdministratorException;
use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Middleware\EnsureEmailVerified;
use App\Modules\Core\Middleware\EnsureUserIsAdmin;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

const PERIMETER_PASSWORD = 'perimeter-password';

/**
 * The aliases the route groups declare. Route::gatherMiddleware() reports these
 * rather than the classes they resolve to, so the resolution itself is asserted
 * separately below — an alias that pointed somewhere else would otherwise satisfy
 * every check here while enforcing nothing.
 */
const PERIMETER_ADMIN_ALIAS = 'admin';
const PERIMETER_VERIFIED_ALIAS = 'email-verified';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    Notification::fake();
});

/**
 * An administrator, verified or not, holding a role so that a refusal cannot be the
 * permission stage in disguise.
 */
function perimeterAdmin(bool $verified, string $email): User
{
    $admin = makeAccount([
        'name' => 'Perimeter Admin',
        'email' => $email,
        'password' => PERIMETER_PASSWORD,
        'account_type' => AccountType::ADMIN,
        'is_active' => true,
        'email_verified_at' => $verified ? now() : null,
    ]);

    app(AdminRbacContract::class)->syncRoles($admin, ['administrator']);

    return $admin;
}

/**
 * @param  array<int, string>  $abilities
 */
function tokenFor(User $user, array $abilities): string
{
    return $user->createToken('perimeter-test', $abilities)->plainTextToken;
}

// ── The fifth stage ──────────────────────────────────────────────────────────

test('a verified administrator reaches an administrative endpoint', function (): void {
    $admin = perimeterAdmin(true, 'verified-admin@example.com');

    $this->withToken(tokenFor($admin, [TokenAbility::ADMIN_ACCESS->value]))
        ->getJson('/api/v1/admin/settings')
        ->assertOk();
});

test('an unverified administrator is refused even holding an admin:access token', function (): void {
    // The token is minted directly rather than by signing in, because sign-in already
    // refuses this account. That is the point: the perimeter must not depend on the
    // issuing path having been correct.
    $admin = perimeterAdmin(false, 'unverified-admin@example.com');

    $this->withToken(tokenFor($admin, [TokenAbility::ADMIN_ACCESS->value]))
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
});

test('verifying afterwards admits the same token', function (): void {
    $admin = perimeterAdmin(false, 'later-verified@example.com');
    $token = tokenFor($admin, [TokenAbility::ADMIN_ACCESS->value]);

    $this->withToken($token)->getJson('/api/v1/admin/settings')->assertStatus(403);

    $admin->markEmailAsVerified();
    resetClient($this);

    $this->withToken($token)->getJson('/api/v1/admin/settings')->assertOk();
});

test('the stage sits after administrative identity and before permission', function (): void {
    // Order is contract. Running before EnsureUserIsAdmin would answer
    // EMAIL_VERIFICATION_REQUIRED to a regular user probing an admin route, which
    // tells them the route exists and what it wants next.
    // gatherMiddleware() reports what the route declared — the aliases — not the
    // classes they resolve to. Asserting on the class names silently matched nothing
    // and passed the coverage check vacuously until this said so.
    $middleware = Route::getRoutes()->getByName('admin.settings.index')->gatherMiddleware();

    $positions = [
        'admin' => array_search(PERIMETER_ADMIN_ALIAS, $middleware, true),
        'verified' => array_search(PERIMETER_VERIFIED_ALIAS, $middleware, true),
    ];

    expect($positions['admin'])->toBeInt()
        ->and($positions['verified'])->toBeInt()
        ->and($positions['verified'])->toBeGreaterThan($positions['admin']);
});

test('the aliases resolve to the middleware they are meant to', function (): void {
    // The coverage checks match on alias strings, so this is what stops them from
    // being satisfied by a name that resolves to nothing or to something else.
    $aliases = app(Kernel::class)->getRouteMiddleware();

    expect($aliases)->toHaveKey(PERIMETER_ADMIN_ALIAS)
        ->and($aliases[PERIMETER_ADMIN_ALIAS])->toBe(EnsureUserIsAdmin::class)
        ->and($aliases)->toHaveKey(PERIMETER_VERIFIED_ALIAS)
        ->and($aliases[PERIMETER_VERIFIED_ALIAS])->toBe(EnsureEmailVerified::class);
});

test('every administrative route carries the stage', function (): void {
    // Each module writes its own perimeter string rather than importing Auth, so the
    // list is repeated by design and a new module can forget an entry. This is what
    // notices.
    $missing = [];

    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        if (! in_array(PERIMETER_ADMIN_ALIAS, $middleware, true)) {
            continue;
        }

        if (! in_array(PERIMETER_VERIFIED_ALIAS, $middleware, true)) {
            $missing[] = $route->methods()[0].' '.$route->uri();
        }
    }

    expect($missing)->toBe([]);
});

test('the stage guards a real and non-trivial number of routes', function (): void {
    // A coverage assertion that would otherwise pass vacuously if the admin
    // middleware were renamed and nothing matched.
    $guarded = collect(Route::getRoutes())
        ->filter(fn ($r): bool => in_array(PERIMETER_VERIFIED_ALIAS, $r->gatherMiddleware(), true))
        ->count();

    expect($guarded)->toBeGreaterThan(20);
});

test('a regular user probing an admin route is still refused for not being an admin', function (): void {
    // The earlier stage answers first, so the refusal says nothing about verification.
    $user = makeAccount(['email' => 'plain@example.com', 'account_type' => AccountType::USER]);

    $this->withToken(tokenFor($user, [TokenAbility::ADMIN_ACCESS->value]))
        ->getJson('/api/v1/admin/settings')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ADMIN_ACCESS_REQUIRED');
});

// ── Issuance: no admin:access without a verified address ─────────────────────

test('signing in unverified yields a scoped credential and no access token', function (): void {
    perimeterAdmin(false, 'signing-in@example.com');

    $response = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'signing-in@example.com',
        'password' => PERIMETER_PASSWORD,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.email_verification_required', true)
        ->assertJsonPath('data.abilities', [TokenAbility::EMAIL_VERIFY->value]);

    expect($response->json('data.token'))->toBeNull();

    // Exactly one token exists and it is the scoped one.
    $tokens = PersonalAccessToken::query()->get();

    expect($tokens)->toHaveCount(1)
        ->and($tokens->first()->abilities)->toBe([TokenAbility::EMAIL_VERIFY->value]);
});

test('the token mint refuses an unverified administrator outright', function (): void {
    // The choke point. Three paths issue an access token and all come through here,
    // so the invariant holds even for a caller that never went near sign-in.
    $admin = perimeterAdmin(false, 'mint@example.com');

    expect(fn () => app(AuthServiceContract::class)->issueToken($admin))
        ->toThrow(UnverifiedAdministratorException::class);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('the mint issues normally once the address is verified', function (): void {
    $admin = perimeterAdmin(false, 'mint-later@example.com');
    $admin->markEmailAsVerified();

    $issued = app(AuthServiceContract::class)->issueToken($admin->refresh());

    expect($issued->ability)->toBe(TokenAbility::ADMIN_ACCESS);
});

test('a regular account is never gated on verification', function (): void {
    makeAccount([
        'email' => 'regular@example.com',
        'password' => PERIMETER_PASSWORD,
        'account_type' => AccountType::USER,
        'email_verified_at' => null,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'regular@example.com',
        'password' => PERIMETER_PASSWORD,
    ])
        ->assertOk()
        ->assertJsonPath('data.abilities', [TokenAbility::USER_ACCESS->value]);
});

// ── The scoped ability reaches exactly one endpoint ──────────────────────────

test('the scoped credential can ask for a verification link', function (): void {
    $admin = perimeterAdmin(false, 'scoped-send@example.com');

    $this->withToken(tokenFor($admin, [TokenAbility::EMAIL_VERIFY->value]))
        ->postJson('/api/v1/auth/email/verify/send')
        ->assertOk();
});

test('the scoped credential reaches nothing else', function (string $method, string $uri, int $status): void {
    $admin = perimeterAdmin(false, 'scoped-refused@example.com');
    $token = tokenFor($admin, [TokenAbility::EMAIL_VERIFY->value]);

    $this->withToken($token)->json($method, $uri)->assertStatus($status);
})->with([
    'me' => ['GET', '/api/v1/auth/me', 403],
    'logout' => ['POST', '/api/v1/auth/logout', 403],
    'mfa status' => ['GET', '/api/v1/auth/mfa', 403],
    'mfa disable' => ['DELETE', '/api/v1/auth/mfa', 403],
    'mfa enrolment' => ['POST', '/api/v1/auth/mfa/enrol', 403],
    'an admin route' => ['GET', '/api/v1/admin/settings', 403],
    'another admin route' => ['GET', '/api/v1/admin/users', 403],
]);

test('the scoped credential cannot be used to mint a better one', function (): void {
    // It reaches no endpoint that issues a token, which is the property that makes it
    // safe to hand to an account that has proved only a password.
    $admin = perimeterAdmin(false, 'no-escalation@example.com');

    $this->withToken(tokenFor($admin, [TokenAbility::EMAIL_VERIFY->value]))
        ->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])
        ->assertStatus(403);

    expect(PersonalAccessToken::query()->where('abilities', 'like', '%admin:access%')->count())->toBe(0);
});

// ── The whole journey ────────────────────────────────────────────────────────

test('an unverified administrator can reach administrative access, in order', function (): void {
    $admin = perimeterAdmin(false, 'journey@example.com');

    // 1. Sign in: a scoped verification credential, nothing more.
    $verificationToken = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'journey@example.com',
        'password' => PERIMETER_PASSWORD,
    ])->json('data.verification_token');

    expect($verificationToken)->toBeString();

    // 2. Ask for the link with it.
    $this->withToken($verificationToken)
        ->postJson('/api/v1/auth/email/verify/send')
        ->assertOk();

    // 3. Follow it.
    $this->getJson(URL::temporarySignedRoute(
        'verification.verify',
        now()->addHour(),
        ['id' => $admin->id, 'hash' => sha1($admin->getEmailForVerification())]
    ))->assertOk();

    expect($admin->refresh()->hasVerifiedEmail())->toBeTrue();

    // 4. Sign in again: now the MFA prerequisite, which was always next.
    resetClient($this);
    $enrolmentToken = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'journey@example.com',
        'password' => PERIMETER_PASSWORD,
    ])
        ->assertJsonPath('data.mfa_setup_required', true)
        ->json('data.enrolment_token');

    // 5. Enrol, and receive the real token in the same response (ADR 0013).
    $secret = $this->withToken($enrolmentToken)
        ->postJson('/api/v1/auth/mfa/enrol', ['type' => 'totp'])
        ->json('data.secret');

    $accessToken = $this->withToken($enrolmentToken)
        ->postJson('/api/v1/auth/mfa/verify', [
            'type' => 'totp',
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])
        ->assertOk()
        ->json('data.token');

    // 6. Administrative access, with both prerequisites behind it.
    resetClient($this);
    $this->withToken($accessToken)->getJson('/api/v1/admin/settings')->assertOk();
});

// ── ADR 0013's MFA invariant, re-proven under the new stage ──────────────────

test('a verified administrator who has not enrolled still receives only mfa:enrol', function (): void {
    perimeterAdmin(true, 'verified-unenrolled@example.com');

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'verified-unenrolled@example.com',
        'password' => PERIMETER_PASSWORD,
    ])
        ->assertOk()
        ->assertJsonPath('data.mfa_setup_required', true)
        ->assertJsonPath('data.abilities', [TokenAbility::MFA_ENROL->value]);
});

test('the enrolment credential still reaches only the enrolment endpoints', function (string $method, string $uri): void {
    $admin = perimeterAdmin(true, 'enrolment-scope@example.com');

    $this->withToken(tokenFor($admin, [TokenAbility::MFA_ENROL->value]))
        ->json($method, $uri)
        ->assertStatus(403);
})->with([
    'me' => ['GET', '/api/v1/auth/me'],
    'logout' => ['POST', '/api/v1/auth/logout'],
    'mfa status' => ['GET', '/api/v1/auth/mfa'],
    'mfa disable' => ['DELETE', '/api/v1/auth/mfa'],
    'an admin route' => ['GET', '/api/v1/admin/settings'],
]);

test('the enrolment credential does not reach the verification send endpoint either', function (): void {
    // The two scoped credentials are disjoint. An enrolment token is for enrolling.
    $admin = perimeterAdmin(true, 'enrolment-not-verify@example.com');

    $this->withToken(tokenFor($admin, [TokenAbility::MFA_ENROL->value]))
        ->postJson('/api/v1/auth/email/verify/send')
        ->assertStatus(403);
});

test('no administrator holds admin:access without both prerequisites', function (): void {
    // The invariant ADR 0012 now states, checked at the only place it can be broken.
    $unverifiedUnenrolled = perimeterAdmin(false, 'neither@example.com');
    $verifiedUnenrolled = perimeterAdmin(true, 'enrolment-only@example.com');

    foreach ([$unverifiedUnenrolled, $verifiedUnenrolled] as $admin) {
        $this->postJson('/api/v1/auth/login', [
            'identifier' => $admin->email,
            'password' => PERIMETER_PASSWORD,
        ])->assertOk();

        resetClient($this);
    }

    $granted = PersonalAccessToken::query()
        ->get()
        ->filter(fn (PersonalAccessToken $t): bool => in_array(TokenAbility::ADMIN_ACCESS->value, $t->abilities ?? [], true));

    expect($granted)->toHaveCount(0);
});
