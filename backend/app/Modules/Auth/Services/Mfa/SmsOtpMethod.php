<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services\Mfa;

use App\Modules\Auth\Contracts\DeliversMfaCodes;
use App\Modules\Auth\Contracts\MfaMethodContract;
use App\Modules\Auth\Data\MfaEnrolment;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Exceptions\MfaEnrolmentException;
use App\Modules\Auth\Models\MfaMethod;
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
 */
class SmsOtpMethod implements DeliversMfaCodes, MfaMethodContract
{
    /**
     * Digits in a delivered code. Six is the familiar length; the security comes from
     * the short lifetime and the attempt throttle, not from length.
     */
    private const CODE_DIGITS = 6;

    private const CODE_LIFETIME_SECONDS = 300; // 5 minutes

    private const RESEND_COOLDOWN_SECONDS = 30;

    public function __construct(private readonly SmsDispatcherContract $sms) {}

    public function type(): MfaType
    {
        return MfaType::SMS_OTP;
    }

    public function codeLifetimeSeconds(): int
    {
        return self::CODE_LIFETIME_SECONDS;
    }

    public function resendCooldownSeconds(): int
    {
        return self::RESEND_COOLDOWN_SECONDS;
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
        $code = $this->generateCode();

        $method->forceFill([
            'otp_hash' => Hash::make($code),
            'otp_expires_at' => now()->addSeconds(self::CODE_LIFETIME_SECONDS),
            'otp_sent_at' => now(),
        ])->save();

        $this->sms->send(new SmsMessage(
            $method->getDestination(),
            'Your '.config('app.name').' verification code is '.$code.'. It expires in '
                .(self::CODE_LIFETIME_SECONDS / 60).' minutes.'
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

    /**
     * A numeric code drawn from a cryptographically secure source.
     */
    private function generateCode(): string
    {
        $max = (10 ** self::CODE_DIGITS) - 1;

        return str_pad((string) random_int(0, $max), self::CODE_DIGITS, '0', STR_PAD_LEFT);
    }
}
