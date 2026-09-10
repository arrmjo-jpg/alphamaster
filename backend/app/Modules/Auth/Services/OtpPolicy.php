<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use Illuminate\Support\Facades\Hash;

/**
 * The rules a one-time code obeys, read from configuration rather than fixed in code.
 *
 * Length, lifetime, resend cooldown and the number of wrong answers a code survives
 * were four constants in `SmsOtpMethod`, which meant an operator whose vendor is slow,
 * or whose recipients are on a network where messages arrive late, had no way to say
 * so. They are settings now, and this class is the one place that reads them — so the
 * MFA code and the phone-verification code cannot drift into two different policies.
 *
 * Each read defends itself against a setting that is absent or nonsense, the way
 * `LoginThrottle` does: a platform whose limits live in settings should still work
 * when the settings store cannot be read.
 */
class OtpPolicy
{
    /** Six is the familiar length; the security is the lifetime and the throttle. */
    private const DEFAULT_DIGITS = 6;

    private const DEFAULT_LIFETIME_SECONDS = 300;

    private const DEFAULT_RESEND_COOLDOWN_SECONDS = 30;

    private const DEFAULT_MAX_ATTEMPTS = 5;

    public function digits(): int
    {
        return $this->positiveInteger('auth.otp_length', self::DEFAULT_DIGITS);
    }

    public function lifetimeSeconds(): int
    {
        return $this->positiveInteger('auth.otp_lifetime_seconds', self::DEFAULT_LIFETIME_SECONDS);
    }

    public function resendCooldownSeconds(): int
    {
        return $this->positiveInteger(
            'auth.otp_resend_cooldown_seconds',
            self::DEFAULT_RESEND_COOLDOWN_SECONDS
        );
    }

    public function maxAttempts(): int
    {
        return $this->positiveInteger('auth.otp_max_attempts', self::DEFAULT_MAX_ATTEMPTS);
    }

    /**
     * The lifetime as an operator would say it, for a message a recipient reads.
     *
     * Rounded up, because a code that says "expires in 1 minute" and dies in ninety
     * seconds is less wrong than one that says two.
     */
    public function lifetimeMinutes(): int
    {
        return (int) ceil($this->lifetimeSeconds() / 60);
    }

    /**
     * A numeric code drawn from a cryptographically secure source.
     */
    public function generate(): string
    {
        $digits = $this->digits();
        $max = (10 ** $digits) - 1;

        return str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * The stored form. Only ever a hash, so a database read yields nothing usable.
     */
    public function hash(string $code): string
    {
        return Hash::make($code);
    }

    private function positiveInteger(string $reference, int $fallback): int
    {
        $configured = setting($reference, $fallback);

        return is_int($configured) && $configured > 0 ? $configured : $fallback;
    }
}
