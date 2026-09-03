<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Brute-force protection for the authentication endpoints (ADR 0022).
 *
 * Limits are read from the Settings module at request time — `security.max_login_attempts`
 * and `security.decay_minutes` — so an operator can tighten them without a deploy.
 *
 * This is endpoint-specific and deliberately narrow. It is not the platform-wide API
 * rate limiting ADR 0022 also calls for; that belongs in Core and is not part of this
 * module.
 */
class LoginThrottle
{
    /**
     * Fallbacks used when the settings are absent, so the endpoint is never
     * unprotected simply because a row is missing.
     */
    private const DEFAULT_MAX_ATTEMPTS = 5;

    private const DEFAULT_DECAY_MINUTES = 1;

    /**
     * Reject the request if the caller has exhausted their attempts.
     *
     * @throws TooManyAttemptsException
     */
    public function assertNotLimited(string $key): void
    {
        if (RateLimiter::tooManyAttempts($key, $this->maxAttempts())) {
            throw new TooManyAttemptsException(RateLimiter::availableIn($key));
        }
    }

    /**
     * Record a failed attempt.
     */
    public function recordFailure(string $key): void
    {
        RateLimiter::hit($key, $this->decaySeconds());
    }

    /**
     * Clear the counter after a successful authentication.
     */
    public function clear(string $key): void
    {
        RateLimiter::clear($key);
    }

    /**
     * Attempts left before the limiter trips.
     */
    public function remaining(string $key): int
    {
        return RateLimiter::remaining($key, $this->maxAttempts());
    }

    /**
     * Build a throttle key scoped to both the identifier and the source address.
     *
     * Keying on the pair means one attacker cannot lock every account out by
     * hammering a single address, while a distributed attack on one account is
     * still limited per source.
     */
    public function key(Request $request, string $scope, string $identifier): string
    {
        return 'auth:'.$scope.':'.sha1(mb_strtolower($identifier).'|'.(string) $request->ip());
    }

    /**
     * Maximum failed attempts, from settings.
     */
    public function maxAttempts(): int
    {
        $configured = setting('security.max_login_attempts', self::DEFAULT_MAX_ATTEMPTS);

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_MAX_ATTEMPTS;
    }

    /**
     * Lockout window in seconds, from settings.
     */
    public function decaySeconds(): int
    {
        $configured = setting('security.decay_minutes', self::DEFAULT_DECAY_MINUTES);
        $minutes = is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_DECAY_MINUTES;

        return $minutes * 60;
    }
}
