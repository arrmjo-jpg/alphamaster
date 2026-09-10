<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\PhoneVerificationException;
use App\Modules\Auth\Models\PhoneVerification;
use App\Modules\Integration\Contracts\SmsDispatcherContract;
use App\Modules\Integration\Data\SmsMessage;
use App\Modules\User\Models\User;
use App\Modules\User\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Confirming that somebody holds the number on their account.
 *
 * A number could already arrive by two routes — the account's own edit and an
 * administrator's — and neither confirmed anything. SMS-MFA enrolment did prove
 * possession, but that is a second factor rather than a property of the account, and
 * an account with no second factor had no way to confirm its number at all.
 *
 * The mechanism is the one MFA already uses, through `OtpPolicy` so that both obey one
 * policy: a code is generated from a secure source, only its hash is stored, a send
 * replaces any outstanding code, a correct answer deletes the row, and a wrong one
 * costs an attempt.
 *
 * Two properties are worth stating because they are easy to leave out.
 *
 * The number the code went to is recorded, as the same keyed digest `users` uses. A
 * code sent to the old number cannot confirm a new one, which would otherwise be a way
 * to have the platform vouch for a number nobody answered.
 *
 * Verification is cleared whenever the number changes, and that happens in the model
 * rather than here — every route that writes a phone goes through the same accessor,
 * so no caller can set a number and leave a stale confirmation behind it.
 */
class PhoneVerifier
{
    public function __construct(
        private readonly OtpPolicy $policy,
        private readonly SmsDispatcherContract $sms,
    ) {}

    /**
     * Send a code to the account's own number.
     *
     * @return string the masked destination, so a client can say where it went
     *
     * @throws PhoneVerificationException
     */
    public function send(User $user): string
    {
        $number = $user->phone;

        if (! is_string($number) || $number === '') {
            throw PhoneVerificationException::noNumber();
        }

        if ($user->phone_verified_at !== null) {
            throw PhoneVerificationException::alreadyVerified();
        }

        $existing = PhoneVerification::query()->where('user_id', $user->id)->first();

        if ($existing !== null) {
            $elapsed = $existing->sent_at->diffInSeconds(now());
            $cooldown = $this->policy->resendCooldownSeconds();

            if ($elapsed < $cooldown) {
                // Every send costs a real message to a real handset. Without this,
                // anyone holding a session can spend an operator's SMS budget, and a
                // client retrying on a spinner does it by accident.
                throw PhoneVerificationException::throttled((int) ceil($cooldown - $elapsed));
            }
        }

        $code = $this->policy->generate();

        PhoneVerification::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'otp_hash' => $this->policy->hash($code),
                'destination_hash' => PhoneNumber::lookupHash($number),
                'expires_at' => now()->addSeconds($this->policy->lifetimeSeconds()),
                'sent_at' => now(),
                // A resend is a fresh code, so it is also a fresh allowance.
                'attempts' => 0,
            ]
        );

        $this->sms->send(new SmsMessage(
            $number,
            'Your '.config('app.name').' phone verification code is '.$code
                .'. It expires in '.$this->policy->lifetimeMinutes().' minutes.'
        ));

        return $this->mask($number);
    }

    /**
     * Answer the outstanding code.
     *
     * @throws PhoneVerificationException
     */
    public function verify(User $user, string $code): void
    {
        // Read the account as it stands, not as the caller happens to hold it. The
        // check below is about which number a code went to, and a model that was
        // loaded a moment ago is a snapshot: an administrator who changed the number
        // in between would otherwise have the platform confirm a number that nobody
        // answered.
        $user->refresh();

        $number = $user->phone;

        if (! is_string($number) || $number === '') {
            throw PhoneVerificationException::noNumber();
        }

        if ($user->phone_verified_at !== null) {
            throw PhoneVerificationException::alreadyVerified();
        }

        /** @var PhoneVerification|null $pending */
        $pending = PhoneVerification::query()->where('user_id', $user->id)->first();

        if ($pending === null || ! $pending->isLive()) {
            throw PhoneVerificationException::invalidCode();
        }

        // The number moved after the code was sent. The code proves somebody answered
        // the *old* number, which is no evidence at all about the new one.
        if (! hash_equals($pending->destination_hash, PhoneNumber::lookupHash($number))) {
            $pending->delete();

            throw PhoneVerificationException::invalidCode();
        }

        if (! Hash::check($code, $pending->otp_hash)) {
            // Counted outside a transaction, deliberately. A refusal has bookkeeping
            // of its own, and rolling back on the way out would undo the attempt it
            // had just recorded — which would make guessing free.
            $pending->increment('attempts');

            if ($pending->attempts >= $this->policy->maxAttempts()) {
                // Guessing costs a resend, and the resend costs the cooldown.
                $pending->delete();
            }

            throw PhoneVerificationException::invalidCode();
        }

        // The success path is the one that writes two things, so it is the one that
        // needs both to land: a confirmed number with its code still outstanding is a
        // code that would confirm an already-confirmed number.
        DB::transaction(function () use ($user, $pending): void {
            $pending->delete();

            // Not fillable, and deliberately: nothing outside this service decides
            // that a number has been confirmed.
            $user->phone_verified_at = now();
            $user->save();
        });
    }

    /**
     * The number as a client may repeat it back — enough to recognise, not enough to
     * dial. The same shape MFA uses for a delivery destination.
     */
    private function mask(string $number): string
    {
        $length = mb_strlen($number);

        return $length <= 4
            ? str_repeat('*', $length)
            : str_repeat('*', $length - 4).mb_substr($number, -4);
    }
}
