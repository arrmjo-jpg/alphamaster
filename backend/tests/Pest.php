<?php

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Password used by every account these helpers create, so a test that signs in
 * separately does not have to guess what makeAccount() set.
 */
const TEST_ACCOUNT_PASSWORD = 'default-test-password';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create an account, setting the guarded account_type deliberately.
 *
 * account_type is excluded from mass assignment on purpose, so it cannot be set
 * through User::create(). Tests go through here for the same reason production goes
 * through the promotion workflow: assigning it is always an explicit act.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeAccount(array $attributes = []): User
{
    $type = $attributes['account_type'] ?? AccountType::USER;
    unset($attributes['account_type']);

    $user = new User(array_merge([
        'name' => 'Test Account',
        'password' => TEST_ACCOUNT_PASSWORD,
        'is_active' => true,
    ], $attributes));

    $user->account_type = $type instanceof AccountType ? $type : AccountType::from((string) $type);
    $user->save();

    return $user;
}

/**
 * Create an administrator and return a Sanctum token carrying the given abilities.
 *
 * @param  array<int, string>  $abilities
 */
function adminToken(array $abilities = ['admin:access'], bool $isAdmin = true, array $roles = []): string
{
    static $sequence = 0;
    $sequence++;

    $admin = makeAccount([
        'name' => 'Test Admin '.$sequence,
        'email' => 'admin'.$sequence.'@example.com',
        'password' => bcrypt('secret'),
        'account_type' => $isAdmin ? AccountType::ADMIN : AccountType::USER,
    ]);

    // Roles are opt-in: clearing the admin perimeter and holding a permission are
    // separate stages, and a helper that granted both would make it impossible to
    // test the second. A caller passing roles must have seeded the catalogue.
    if ($roles !== []) {
        app(AdminRbacContract::class)->syncRoles($admin, $roles);
    }

    return $admin->createToken('test-token', $abilities)->plainTextToken;
}

/**
 * An administrator holding exactly the named permissions and no role.
 *
 * The seeded roles are deliberately coarse — super_admin holds everything, and the
 * others hold coherent job-shaped sets — so they cannot express the combinations a
 * privilege test needs: "may roll back but may not change security settings", "may
 * change a value but may not rotate a credential". Those are exactly the combinations
 * an escalation runs through, so they are built here rather than by bending a role.
 *
 * @param  array<int, string>  $permissions
 */
function tokenWithPermissions(array $permissions): string
{
    static $sequence = 0;
    $sequence++;

    $admin = makeAccount([
        'name' => 'Scoped Operator '.$sequence,
        'email' => 'scoped-operator'.$sequence.'@example.com',
        'password' => bcrypt('secret'),
        'account_type' => AccountType::ADMIN,
    ]);

    $admin->givePermissionTo($permissions);

    return $admin->createToken('test-token', ['admin:access'])->plainTextToken;
}

/**
 * Return the test client to a genuinely unauthenticated state.
 *
 * withToken() persists the Authorization header across requests, and
 * AttachRequestContext resolves $request->user() on every API route, which caches the
 * resolution on the guard for the lifetime of this application instance. Production
 * builds a fresh instance per request; a test has to clear both by hand.
 */
function resetClient(mixed $test): void
{
    $test->flushHeaders();
    app('auth')->forgetGuards();
}

/**
 * Drive an administrator through mandatory MFA enrolment and return the resulting
 * access token together with the material needed to sign in again.
 *
 * MFA is compulsory for administrators (ADR 0013), so login alone no longer yields a
 * token: the enrolment credential has to be exchanged for one.
 *
 * @return array{token: string, secret: string, recovery: array<int, string>}
 */
function signInAdminWithMfa(mixed $test, string $email, string $password): array
{
    $enrolmentToken = $test->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => $password,
    ])->json('data.enrolment_token');

    $secret = $test->withToken($enrolmentToken)
        ->postJson('/api/v1/auth/mfa/enrol')
        ->json('data.secret');

    $verified = $test->withToken($enrolmentToken)->postJson('/api/v1/auth/mfa/verify', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ]);

    // Leave no stale credential or cached guard resolution behind for the caller.
    resetClient($test);

    return [
        'token' => $verified->json('data.token'),
        'secret' => $secret,
        'recovery' => $verified->json('data.recovery_codes'),
    ];
}

/**
 * An enrolled administrator holding the given roles, plus a usable admin token.
 *
 * @param  array<int, string>  $roles
 * @return array{user: User, token: string}
 */
function adminWithRoles(mixed $test, array $roles, string $email = 'rbac-admin@example.com'): array
{
    $admin = makeAccount([
        'name' => 'RBAC Admin',
        'email' => $email,
        'password' => TEST_ACCOUNT_PASSWORD,
        'account_type' => AccountType::ADMIN,
    ]);

    if ($roles !== []) {
        app(AdminRbacContract::class)->syncRoles($admin, $roles);
    }

    // MFA is mandatory for administrators, so a real token requires enrolment.
    $token = signInAdminWithMfa($test, $email, TEST_ACCOUNT_PASSWORD)['token'];

    return ['user' => $admin->refresh(), 'token' => $token];
}

/**
 * A regular account with a usable user:access token.
 *
 * @return array{user: User, token: string}
 */
function regularWithToken(mixed $test, string $email = 'rbac-user@example.com'): array
{
    $user = makeAccount([
        'name' => 'RBAC User',
        'email' => $email,
        'password' => TEST_ACCOUNT_PASSWORD,
        'account_type' => AccountType::USER,
    ]);

    resetClient($test);
    $token = $test->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => TEST_ACCOUNT_PASSWORD,
    ])->json('data.token');

    resetClient($test);

    return ['user' => $user, 'token' => $token];
}

/**
 * The key the platform cache is using for a localization resource.
 *
 * Asked for rather than composed: the layout belongs to the cache (ADR 0035).
 */
function localizationKey(string $resource): string
{
    return app(PlatformCacheContract::class)->key(CacheNamespace::LOCALIZATION, $resource);
}

/**
 * The current validator for a settings group (ADR 0038).
 *
 * An admin write must state which version it is changing, so a test that updates
 * settings reads the version the same way a client would.
 */
function settingsVersion(string $group): string
{
    return app(SettingServiceInterface::class)->groupVersion($group);
}
