<?php

declare(strict_types=1);

namespace App\Modules\User\Console;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\User\Contracts\AccountTypeManagerContract;
use App\Modules\User\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Create the administrator a fresh installation has no other way to get.
 *
 * Every route into administrative standing needs an administrator already holding
 * it: promotion is an authenticated endpoint behind the admin perimeter, and the
 * seeders create languages, settings, permissions, providers and templates but
 * deliberately no accounts. That is correct — an account with a password does not
 * belong in a seeder that runs on every deploy — and it leaves exactly one gap, which
 * this fills.
 *
 * **Bootstrap, not administration.** It refuses to run once an administrator exists,
 * and it has no --force. A second administrator is created by an existing one through
 * the promotion workflow, where the act is authenticated, authorised and audited; a
 * console command that could keep minting them would be a way around all three, and
 * would be indistinguishable in a shell history from the legitimate first run.
 *
 * **The account it creates is pre-verified**, and that is a consequence of ADR 0012
 * rather than a convenience. An administrator must have a verified address to hold
 * admin:access, and verification arrives by email — so an installation whose mail is
 * not yet configured would create an administrator who cannot verify, cannot reach
 * the Admin API, and cannot configure the mail that would let them. The operator
 * running this command is standing at the machine; that is a stronger proof of
 * control than a link in an inbox.
 *
 * **MFA is not enrolled**, and that is the opposite decision for the opposite reason.
 * Enrolment proves possession of a factor, which nothing here can do on the
 * administrator's behalf. The first sign-in goes through the normal enrolment flow of
 * ADR 0013, exactly as every later administrator's does.
 */
class CreateFirstAdministratorCommand extends Command
{
    protected $signature = 'admin:bootstrap
                            {--name= : The administrator display name}
                            {--email= : The administrator email address}';

    protected $description = 'Create the first administrator on a fresh installation';

    /**
     * The role the first administrator holds.
     *
     * The most capable one, because there is nobody else to grant anything: an
     * installation whose only administrator could not manage roles would need a
     * second command to fix the first.
     */
    private const ROLE = 'super_admin';

    public function handle(
        AccountTypeManagerContract $accountTypes,
        AdminRbacContract $rbac,
    ): int {
        if (User::query()->admins()->exists()) {
            $this->error('An administrator already exists.');
            $this->line('This command bootstraps the first one only. Create further administrators');
            $this->line('by promoting an account from the Admin API, where the act is authenticated');
            $this->line('and recorded.');

            return self::FAILURE;
        }

        if ($rbac->roles()->where('name', self::ROLE)->isEmpty()) {
            $this->error('The role ['.self::ROLE.'] does not exist.');
            $this->line('Seed the authorization catalogue first: php artisan db:seed');

            return self::FAILURE;
        }

        $name = $this->stringOption('name') ?? (string) $this->ask('Name');
        $email = mb_strtolower(trim($this->stringOption('email') ?? (string) $this->ask('Email address')));

        if (! $this->validAccount($name, $email)) {
            return self::FAILURE;
        }

        $password = $this->promptForPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        try {
            $admin = $this->create($name, $email, $password, $accountTypes, $rbac);
        } catch (Throwable $e) {
            // Deliberately the class and not the message: an exception raised while
            // creating an account can carry the values it was handed.
            $this->error('Could not create the administrator ['.$e::class.'].');

            return self::FAILURE;
        }

        $this->info('Administrator created.');
        $this->line('  name   '.$admin->name);
        $this->line('  email  '.$admin->email);
        $this->line('  role   '.self::ROLE);
        $this->newLine();
        $this->line('The address is already verified, so the Admin API is reachable.');
        $this->line('Multi-factor authentication is not enrolled: the first sign-in will');
        $this->line('return an enrolment credential and ask for a second factor (ADR 0013).');

        return self::SUCCESS;
    }

    /**
     * Create the account, promote it, verify it and grant the role, in that order.
     *
     * Promotion goes through AccountTypeManager rather than assigning account_type
     * here, because that service is documented as the only sanctioned way to move an
     * account across the administrative boundary, and a command that took a shortcut
     * would be the exception that makes the rule untrue. It also has to run before the
     * role is granted: admin RBAC refuses an account that is not an administrator.
     */
    private function create(
        string $name,
        string $email,
        string $password,
        AccountTypeManagerContract $accountTypes,
        AdminRbacContract $rbac,
    ): User {
        $user = new User([
            'name' => $name,
            'email' => $email,
            // Cast to `hashed` on the model, so the plaintext never reaches a column.
            'password' => $password,
            'is_active' => true,
        ]);
        $user->save();

        $admin = $accountTypes->promote($user);

        $admin->markEmailAsVerified();
        $rbac->assignRole($admin, self::ROLE);

        return $admin->refresh();
    }

    /**
     * Ask twice, never echo, and never accept it as an option.
     *
     * A --password option would put the credential in the shell history, in the
     * process table while it runs, and in any CI log that echoes its commands. There
     * is no way to offer it safely, so it is not offered.
     */
    private function promptForPassword(): ?string
    {
        $minimum = $this->minimumPasswordLength();

        $password = (string) $this->secret('Password (minimum '.$minimum.' characters)');
        $confirmation = (string) $this->secret('Confirm password');

        if (mb_strlen($password) < $minimum) {
            $this->error('The password must be at least '.$minimum.' characters.');

            return null;
        }

        // hash_equals rather than ===, for the habit rather than the threat: nothing
        // adversarial reaches this comparison, and a codebase that compares secrets
        // loosely in one place teaches the wrong reflex for the places where it matters.
        if (! hash_equals($password, $confirmation)) {
            $this->error('The passwords do not match.');

            return null;
        }

        return $password;
    }

    /**
     * The configured minimum, from the same setting the rest of the platform reads.
     */
    private function minimumPasswordLength(): int
    {
        $configured = setting('auth.password_min_length', 8);

        return is_int($configured) && $configured > 0 ? $configured : 8;
    }

    private function validAccount(string $name, string $email): bool
    {
        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return false;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('An account with that email address already exists.');

            return false;
        }

        return true;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
