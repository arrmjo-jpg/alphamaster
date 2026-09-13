<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Data\PhoneSignInResult;
use App\Modules\Auth\Exceptions\AccountInactiveException;
use App\Modules\Auth\Exceptions\SelfServiceAuthException;
use App\Modules\Auth\Models\PhoneSignInCode;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Integration\Contracts\SmsDispatcherContract;
use App\Modules\Integration\Data\SmsMessage;
use App\Modules\User\Exceptions\InvalidPhoneNumberException;
use App\Modules\User\Models\User;
use App\Modules\User\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Signing in, or registering, with a phone number and a one-time code (ADR 0051 §1).
 *
 * ## Nothing says whether an account exists
 *
 * A code is sent only to a number that may use one: an active user account's, or a
 * number that belongs to nobody while registration is open. Every other number — an
 * administrator's, a suspended account's, one still inside its resend cooldown, an
 * unknown one while registration is closed — is answered exactly as a send is, and
 * nothing is sent. The controller's answer does not depend on which happened.
 *
 * ## One code, two outcomes
 *
 * The code is keyed by the number's lookup digest rather than by an account, so the same
 * answer signs an existing user in or creates the account. It obeys `OtpPolicy` — the
 * same length, lifetime, cooldown and attempt limit as every other code — and only its
 * hash is stored.
 *
 * Answering a code proves somebody holds the number, so the number is marked verified.
 * It never writes `email_verified_at`, and it never signs an administrator in.
 */
class PhoneSignInService
{
    public function __construct(
        private readonly OtpPolicy $policy,
        private readonly SmsDispatcherContract $sms,
        private readonly AuditRecorderContract $audit,
    ) {}

    public function isEnabled(): bool
    {
        return setting('auth.phone_sign_in_enabled', false) === true;
    }

    public function codeLifetimeSeconds(): int
    {
        return $this->policy->lifetimeSeconds();
    }

    public function resendCooldownSeconds(): int
    {
        return $this->policy->resendCooldownSeconds();
    }

    /**
     * Send a code if this number may use one. Answers nothing about whether it did.
     *
     * @throws SelfServiceAuthException when phone sign-in is switched off
     */
    public function sendCode(string $phone): void
    {
        if (! $this->isEnabled()) {
            throw SelfServiceAuthException::phoneSignInUnavailable();
        }

        $canonical = $this->canonical($phone);

        if ($canonical === null) {
            return;
        }

        $hash = PhoneNumber::lookupHash($canonical);
        $account = User::query()->where('phone_hash', $hash)->first();

        if ($account !== null && ($account->isAdmin() || ! $account->is_active)) {
            return;
        }

        if ($account === null && ! $this->registrationOpen()) {
            return;
        }

        $existing = PhoneSignInCode::query()->where('phone_hash', $hash)->first();

        // Every send is a real message on the operator's bill. Inside the cooldown the
        // request is answered like any other and nothing is sent.
        if ($existing !== null && $existing->sent_at->diffInSeconds(now()) < $this->policy->resendCooldownSeconds()) {
            return;
        }

        $code = $this->policy->generate();

        PhoneSignInCode::query()->updateOrCreate(
            ['phone_hash' => $hash],
            [
                'otp_hash' => $this->policy->hash($code),
                'expires_at' => now()->addSeconds($this->policy->lifetimeSeconds()),
                'sent_at' => now(),
                // A fresh code is a fresh allowance.
                'attempts' => 0,
            ]
        );

        $this->sms->send(new SmsMessage(
            $canonical,
            'Your '.config('app.name').' sign-in code is '.$code
                .'. It expires in '.$this->policy->lifetimeMinutes().' minutes.'
        ));
    }

    /**
     * Answer a code: sign the number's account in, or create it.
     *
     * @throws SelfServiceAuthException
     * @throws AccountInactiveException
     */
    public function verify(string $phone, string $code, ?string $name, ?string $preferredLocale): PhoneSignInResult
    {
        if (! $this->isEnabled()) {
            throw SelfServiceAuthException::phoneSignInUnavailable();
        }

        $canonical = $this->canonical($phone);

        if ($canonical === null) {
            throw SelfServiceAuthException::invalidCode();
        }

        $hash = PhoneNumber::lookupHash($canonical);

        /** @var PhoneSignInCode|null $pending */
        $pending = PhoneSignInCode::query()->where('phone_hash', $hash)->first();

        if ($pending === null || ! $pending->isLive()) {
            throw SelfServiceAuthException::invalidCode();
        }

        if (! Hash::check($code, $pending->otp_hash)) {
            // Counted outside any transaction: rolling back on the way out would undo
            // the attempt just recorded and make guessing free.
            $pending->increment('attempts');

            if ($pending->attempts >= $this->policy->maxAttempts()) {
                $pending->delete();
            }

            throw SelfServiceAuthException::invalidCode();
        }

        $account = User::query()->where('phone_hash', $hash)->first();

        if ($account !== null) {
            return $this->signIn($account, $pending);
        }

        if (! $this->registrationOpen()) {
            $pending->delete();

            throw SelfServiceAuthException::registrationClosed();
        }

        $name = trim((string) $name);

        // The code is not spent: the holder of the number resends with a name.
        if ($name === '') {
            throw SelfServiceAuthException::registrationDetailsRequired();
        }

        try {
            return DB::transaction(function () use ($pending, $canonical, $name, $preferredLocale): PhoneSignInResult {
                $pending->delete();

                $user = new User([
                    'name' => mb_substr($name, 0, 255),
                    'phone' => $canonical,
                    'email' => null,
                    'password' => null,
                    'preferred_locale' => $preferredLocale,
                    'is_active' => true,
                ]);

                // After the phone is assigned: assigning a number clears its verification,
                // and this one was just proven by the code.
                $user->phone_verified_at = now();
                $user->save();

                // The account and the record of it commit together (ADR 0037).
                $this->audit->succeeded(AuditAction::ACCOUNT_CREATED, $user->id, [
                    'active' => true,
                    'source' => 'phone',
                ]);

                return new PhoneSignInResult($user->refresh(), created: true);
            });
        } catch (UniqueConstraintViolationException) {
            // Another request registered this number in the same instant.
            throw SelfServiceAuthException::invalidCode();
        }
    }

    /**
     * @throws SelfServiceAuthException
     * @throws AccountInactiveException
     */
    private function signIn(User $account, PhoneSignInCode $pending): PhoneSignInResult
    {
        // Unreachable in ordinary operation — neither number is ever sent a code — and
        // checked anyway, because this is the step that would otherwise mint a token.
        if ($account->isAdmin()) {
            $pending->delete();

            throw SelfServiceAuthException::invalidCode();
        }

        if (! $account->is_active) {
            $pending->delete();

            throw new AccountInactiveException;
        }

        DB::transaction(function () use ($account, $pending): void {
            $pending->delete();

            if ($account->phone_verified_at === null) {
                $account->phone_verified_at = now();
                $account->save();
            }
        });

        return new PhoneSignInResult($account->refresh(), created: false);
    }

    private function canonical(string $phone): ?string
    {
        try {
            return PhoneNumber::canonicalise($phone);
        } catch (InvalidPhoneNumberException) {
            return null;
        }
    }

    private function registrationOpen(): bool
    {
        return setting('auth.registration_enabled', false) === true;
    }
}
