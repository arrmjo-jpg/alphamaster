<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\EmailVerificationThrottledException;
use App\Modules\Auth\Exceptions\SelfServiceAuthException;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\User\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening a user account with an email address and a password (ADR 0051 §3).
 *
 * The account is always a user. A verification link is sent, and `email_verified_at` is
 * written by that link and nothing else. A number given here is stored unverified:
 * confirming it is phone verification's job.
 */
class RegistrationService
{
    public function __construct(
        private readonly AuditRecorderContract $audit,
        private readonly EmailVerificationService $verification,
    ) {}

    public function isOpen(): bool
    {
        return setting('auth.registration_enabled', false) === true;
    }

    /**
     * @return array{user: User, verification_sent: bool}
     *
     * @throws SelfServiceAuthException
     * @throws ValidationException
     */
    public function register(string $name, string $email, string $password, ?string $phone, ?string $preferredLocale): array
    {
        if (! $this->isOpen()) {
            throw SelfServiceAuthException::registrationClosed();
        }

        try {
            $user = DB::transaction(function () use ($name, $email, $password, $phone, $preferredLocale): User {
                $user = new User([
                    'name' => $name,
                    'email' => mb_strtolower(trim($email)),
                    'password' => $password,
                    'phone' => $phone,
                    'preferred_locale' => $preferredLocale,
                    'is_active' => true,
                ]);

                $user->save();

                $this->audit->succeeded(AuditAction::ACCOUNT_CREATED, $user->id, [
                    'active' => true,
                    'source' => 'registration',
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Validation checked, and another request took the address or number in the
            // same instant. Answered the way validation would have answered.
            throw ValidationException::withMessages([
                'email' => [(string) __('validation.unique', ['attribute' => 'email'])],
            ]);
        }

        try {
            $sent = $this->verification->send($user);
        } catch (EmailVerificationThrottledException) {
            $sent = false;
        }

        return ['user' => $user->refresh(), 'verification_sent' => $sent];
    }
}
