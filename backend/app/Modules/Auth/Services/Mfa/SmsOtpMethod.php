<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services\Mfa;

use App\Modules\Auth\Contracts\DeliversMfaCodes;
use App\Modules\Auth\Contracts\MfaMethodContract;
use App\Modules\Auth\Data\MfaEnrolment;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Exceptions\MfaEnrolmentException;
use App\Modules\Auth\Models\MfaMethod;
use App\Modules\Auth\Services\OtpPolicy;
use App\Modules\Integration\Contracts\SmsDispatcherContract;
use App\Modules\Integration\Data\SmsMessage;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * One-time codes delivered by SMS, dispatched through the Integration module.
 *
 * This is the method ADR 0013 deferred until a delivery channel existed. It owns no
 * transport of its own: provider selection, failover and usage logging all belong to
 * Integration (ADR 0017), so a vendor change is invisible here.
 *
 * It owns no code policy either. Length, lifetime and cooldown were constants here
 * until phone verification needed the same three; both read `OtpPolicy` now, so an
 * operator changing the lifetime changes it for every code the platform sends rather
 * than for one of the two places that send one.
 */
class SmsOtpMethod implements DeliversMfaCodes, MfaMethodContract
{
    public function __construct(
        private readonly SmsDispatcherContract $sms,
        private readonly OtpPolicy $policy,
    ) {}

    public function type(): MfaType
    {
        return MfaType::SMS_OTP;
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
     * Record the destination and send a first code to prove the user holds it.
     *
     * Enrolment is the verification: a number is only ever confirmed by answering a
     * code sent to it, so there is no separate unverified-phone state to reason about.
     *
     * @param  array<string, mixed>  $options  requires a 'phone' entry
     */
    public function enrol(User $user, array $options = []): MfaEnrolment
    {
        $phone = trim((string) ($options['phone'] ?? ''));

        if ($phone === '') {
            throw new MfaEnrolmentException('api.error.auth.mfa_phone_required');
        }

        $method = MfaMethod::query()->firstOrNew([
            'user_id' => $user->id,
            'type' => MfaType::SMS_OTP->value,
        ]);

        $method->type = MfaType::SMS_OTP;
        $method->confirmed_at = null;
        $method->secret = null;
        $method->setDestination($phone);
        $method->save();

        $this->deliver($user, $method);

        return MfaEnrolment::forDelivery($this->type(), $method->maskedDestination());
    }

    /**
     * Generate a fresh code, store only its hash, and dispatch it.
     *
     * Replacing any outstanding code means a resend invalidates its predecessor, so
     * two live codes never exist for one account.
     */
    public function deliver(User $user, MfaMethod $method): string
    {
        $code = $this->policy->generate();

        $method->forceFill([
            'otp_hash' => $this->policy->hash($code),
            'otp_expires_at' => now()->addSeconds($this->policy->lifetimeSeconds()),
            'otp_sent_at' => now(),
        ])->save();

        $this->sms->send(new SmsMessage(
            $method->getDestination(),
            'Your '.config('app.name').' verification code is '.$code.'. It expires in '
                .$this->policy->lifetimeMinutes().' minutes.'
        ));

        return $method->maskedDestination();
    }

    /**
     * Verify a delivered code.
     *
     * A code is single use and dies on the first correct presentation, so it cannot be
     * replayed inside its remaining lifetime. An expired code is refused without
     * comparison.
     */
    public function verify(User $user, string $code): bool
    {
        $method = MfaMethod::query()
            ->where('user_id', $user->id)
            ->where('type', MfaType::SMS_OTP->value)
            ->first();

        if ($method === null || ! $method->hasPendingOtp()) {
            return false;
        }

        if (! Hash::check($code, (string) $method->otp_hash)) {
            return false;
        }

        $method->clearOtp();

        return true;
    }
}
