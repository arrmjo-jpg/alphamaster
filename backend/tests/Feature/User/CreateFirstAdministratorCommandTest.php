<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Models\MfaMethod;
use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

const BOOTSTRAP_PASSWORD = 'a-sufficiently-long-password';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

/**
 * Run the command with the password prompts answered.
 *
 * Only for paths that actually reach the prompt. The command refuses before asking
 * for a password whenever it can — an administrator already exists, the catalogue is
 * unseeded, the email is taken or malformed — and expectsQuestion() on a question
 * that is never asked fails, which is the correct behaviour and why those tests call
 * artisan() directly.
 */
function bootstrapAdmin(
    mixed $test,
    string $password = BOOTSTRAP_PASSWORD,
    ?string $confirmation = null,
    string $email = 'first@example.com',
) {
    return $test->artisan('admin:bootstrap', ['--name' => 'First Administrator', '--email' => $email])
        ->expectsQuestion('Password (minimum 8 characters)', $password)
        ->expectsQuestion('Confirm password', $confirmation ?? $password);
}

// ── The happy path ───────────────────────────────────────────────────────────

test('it creates the first administrator', function (): void {
    bootstrapAdmin($this)->assertExitCode(0);

    $admin = User::query()->where('email', 'first@example.com')->firstOrFail();

    expect($admin->name)->toBe('First Administrator')
        ->and($admin->account_type)->toBe(AccountType::ADMIN);
});

test('the account is active', function (): void {
    bootstrapAdmin($this)->assertExitCode(0);

    expect(User::query()->where('email', 'first@example.com')->firstOrFail()->is_active)->toBeTrue();
});

test('the address is already verified, so the perimeter admits it', function (): void {
    // Without this the account would be locked out by ADR 0012's fifth stage, and
    // could not configure the mail that would send it a verification link.
    bootstrapAdmin($this)->assertExitCode(0);

    expect(User::query()->where('email', 'first@example.com')->firstOrFail()->hasVerifiedEmail())->toBeTrue();
});

test('it holds the administrator role and its permissions', function (): void {
    bootstrapAdmin($this)->assertExitCode(0);

    $admin = User::query()->where('email', 'first@example.com')->firstOrFail();
    $rbac = app(AdminRbacContract::class);

    expect($rbac->rolesFor($admin))->toContain('super_admin')
        ->and($rbac->permissionsFor($admin))->not->toBeEmpty();
});

test('the password is stored hashed, never in clear', function (): void {
    bootstrapAdmin($this)->assertExitCode(0);

    $stored = (string) User::query()->where('email', 'first@example.com')->value('password');

    expect($stored)->not->toBe(BOOTSTRAP_PASSWORD)
        ->and(Hash::check(BOOTSTRAP_PASSWORD, $stored))->toBeTrue();
});

// ── MFA is not enrolled ──────────────────────────────────────────────────────

test('multi-factor authentication is not enrolled for it', function (): void {
    bootstrapAdmin($this)->assertExitCode(0);

    $admin = User::query()->where('email', 'first@example.com')->firstOrFail();

    expect(MfaMethod::query()->where('user_id', $admin->id)->count())->toBe(0);
});

test('its first sign-in goes through enrolment and yields no access token', function (): void {
    // The integration this command exists to make possible, and the one it must not
    // short-circuit: verification is satisfied, so sign-in reaches the MFA stage.
    bootstrapAdmin($this)->assertExitCode(0);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'first@example.com',
        'password' => BOOTSTRAP_PASSWORD,
    ])
        ->assertOk()
        ->assertJsonPath('data.mfa_setup_required', true)
        ->assertJsonPath('data.abilities', [TokenAbility::MFA_ENROL->value]);

    $granted = PersonalAccessToken::query()
        ->get()
        ->filter(fn (PersonalAccessToken $t): bool => in_array(TokenAbility::ADMIN_ACCESS->value, $t->abilities ?? [], true));

    expect($granted)->toHaveCount(0);
});

// ── It bootstraps once ───────────────────────────────────────────────────────

test('it refuses once an administrator exists', function (): void {
    bootstrapAdmin($this)->assertExitCode(0);

    $this->artisan('admin:bootstrap', ['--name' => 'Second', '--email' => 'second@example.com'])
        ->expectsOutputToContain('An administrator already exists.')
        ->assertExitCode(1);

    expect(User::query()->where('email', 'second@example.com')->exists())->toBeFalse();
});

test('it refuses even when the existing administrator is suspended', function (): void {
    // A disabled administrator is still an administrator. Treating one as absent
    // would make this a way to mint a new super_admin whenever the only one is locked.
    makeAccount([
        'email' => 'suspended-admin@example.com',
        'account_type' => AccountType::ADMIN,
        'is_active' => false,
    ]);

    // No password is asked for: the refusal comes first.
    $this->artisan('admin:bootstrap', ['--name' => 'First Administrator', '--email' => 'first@example.com'])
        ->expectsOutputToContain('An administrator already exists.')
        ->assertExitCode(1);

    expect(User::query()->where('email', 'first@example.com')->exists())->toBeFalse();
});

test('a regular account does not block the bootstrap', function (): void {
    makeAccount(['email' => 'regular@example.com', 'account_type' => AccountType::USER]);

    bootstrapAdmin($this)->assertExitCode(0);

    expect(User::query()->admins()->count())->toBe(1);
});

test('there is no force option to override the refusal', function (): void {
    // A command that could keep minting administrators would be a way around the
    // authenticated, authorised, audited promotion workflow.
    $definition = $this->app->make(Kernel::class)
        ->all()['admin:bootstrap']
        ->getDefinition();

    expect($definition->hasOption('force'))->toBeFalse()
        ->and(array_keys($definition->getOptions()))->toContain('name', 'email');
});

// ── Invalid input ────────────────────────────────────────────────────────────

test('it refuses an email that is already in use', function (): void {
    makeAccount(['email' => 'taken@example.com', 'account_type' => AccountType::USER]);

    $this->artisan('admin:bootstrap', ['--name' => 'First', '--email' => 'taken@example.com'])
        ->expectsOutputToContain('An account with that email address already exists.')
        ->assertExitCode(1);

    expect(User::query()->admins()->count())->toBe(0);
});

test('it refuses a malformed email', function (): void {
    $this->artisan('admin:bootstrap', ['--name' => 'First', '--email' => 'not-an-email'])
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

test('it refuses a password shorter than the configured minimum', function (): void {
    bootstrapAdmin($this, password: 'short')
        ->expectsOutputToContain('The password must be at least 8 characters.')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

test('it refuses when the confirmation does not match', function (): void {
    bootstrapAdmin($this, password: BOOTSTRAP_PASSWORD, confirmation: 'something-else-entirely')
        ->expectsOutputToContain('The passwords do not match.')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

test('it takes the minimum from the platform setting rather than a constant', function (): void {
    app(SettingServiceInterface::class)
        ->set('auth', 'password_min_length', 20);
    Cache::flush();

    // Twelve characters: comfortably over the shipped default of eight, and under
    // the raised minimum. It is refused only if the setting is what is being read.
    $this->artisan('admin:bootstrap', ['--name' => 'First', '--email' => 'first@example.com'])
        ->expectsQuestion('Password (minimum 20 characters)', 'twelve-chars')
        ->expectsQuestion('Confirm password', 'twelve-chars')
        ->expectsOutputToContain('The password must be at least 20 characters.')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

test('it refuses when the authorization catalogue has not been seeded', function (): void {
    Role::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Checked before anything is asked for, so an operator is not made to type a
    // password the command was never going to use.
    $this->artisan('admin:bootstrap', ['--name' => 'First', '--email' => 'first@example.com'])
        ->expectsOutputToContain('does not exist')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

// ── The password never leaves the prompt ─────────────────────────────────────

test('the password appears nowhere in the command output', function (): void {
    $this->artisan('admin:bootstrap', ['--name' => 'First Administrator', '--email' => 'first@example.com'])
        ->expectsQuestion('Password (minimum 8 characters)', BOOTSTRAP_PASSWORD)
        ->expectsQuestion('Confirm password', BOOTSTRAP_PASSWORD)
        ->doesntExpectOutputToContain(BOOTSTRAP_PASSWORD)
        ->assertExitCode(0);
});

test('the password cannot be supplied as an option, so it cannot reach a shell history', function (): void {
    $definition = $this->app->make(Kernel::class)
        ->all()['admin:bootstrap']
        ->getDefinition();

    expect($definition->hasOption('password'))->toBeFalse();
});

test('a failure during creation reports the class and not the values', function (): void {
    // An exception raised while writing an account can carry the attributes it was
    // handed. The command reports what failed, never what it was given.
    $this->artisan('admin:bootstrap', ['--name' => str_repeat('x', 300), '--email' => 'first@example.com'])
        ->doesntExpectOutputToContain(str_repeat('x', 300))
        ->assertExitCode(1);
});
