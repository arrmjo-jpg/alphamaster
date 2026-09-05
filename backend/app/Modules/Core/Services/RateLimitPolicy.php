<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

/**
 * The limits the central API limiter enforces, read from Settings.
 *
 * Limits live in Settings rather than config so an operator can change one
 * without a deploy, and `SettingService` invalidates its cache after the
 * transaction commits — so a change takes effect on the next request rather
 * than after a TTL.
 *
 * Every read is guarded the way `LoginThrottle` guards its own: a setting that
 * is missing, non-integer, or zero falls back to the default. A limiter that
 * read a null would either raise on every request or admit an unbounded number
 * of them, and both are worse than a limit nobody edited.
 */
class RateLimitPolicy
{
    public const PUBLIC_READ = 'public-read';

    public const AUTH = 'auth';

    public const READ = 'read';

    public const WRITE = 'write';

    public const UPLOAD = 'upload';

    /**
     * Attempts and the window they are counted over, per class.
     *
     * `auth` deliberately shares the anonymous ceiling: those routes are
     * unauthenticated, and the throttles already on them — LoginThrottle at five
     * attempts a minute, and the MFA resend cooldown — are far tighter, so this
     * only bounds an attacker rotating identifiers, which those throttles cannot
     * see because their key includes the identifier.
     *
     * @var array<string, array{setting: string, default: int, decayMinutes: int}>
     */
    private const CLASSES = [
        self::PUBLIC_READ => ['setting' => 'public_read_per_minute', 'default' => 60, 'decayMinutes' => 1],
        self::AUTH => ['setting' => 'public_read_per_minute', 'default' => 60, 'decayMinutes' => 1],
        self::READ => ['setting' => 'read_per_minute', 'default' => 120, 'decayMinutes' => 1],
        self::WRITE => ['setting' => 'write_per_minute', 'default' => 30, 'decayMinutes' => 1],
        self::UPLOAD => ['setting' => 'upload_per_hour', 'default' => 20, 'decayMinutes' => 60],
    ];

    private const DEFAULT_IP_MULTIPLIER = 4;

    /**
     * Every class this limiter knows, so a caller cannot name one that does not exist.
     *
     * @return array<int, string>
     */
    public static function classes(): array
    {
        return array_keys(self::CLASSES);
    }

    /**
     * The per-identity allowance for a class.
     */
    public function maxAttempts(string $class): int
    {
        $definition = self::CLASSES[$class] ?? self::CLASSES[self::PUBLIC_READ];

        return $this->positiveInt(
            setting('rate_limit.'.$definition['setting']),
            $definition['default']
        );
    }

    /**
     * The window that allowance is counted over.
     */
    public function decayMinutes(string $class): int
    {
        $definition = self::CLASSES[$class] ?? self::CLASSES[self::PUBLIC_READ];

        return $definition['decayMinutes'];
    }

    /**
     * The per-IP allowance for an authenticated request.
     *
     * Looser than the per-user one on purpose. An office or a mobile carrier puts
     * many legitimate users behind one address, and a per-IP ceiling equal to the
     * per-user one would throttle the second colleague to open the admin.
     */
    public function maxAttemptsForIp(string $class): int
    {
        return $this->maxAttempts($class) * $this->ipMultiplier();
    }

    public function ipMultiplier(): int
    {
        return $this->positiveInt(
            setting('rate_limit.authenticated_ip_multiplier'),
            self::DEFAULT_IP_MULTIPLIER
        );
    }

    private function positiveInt(mixed $configured, int $default): int
    {
        return is_int($configured) && $configured > 0 ? $configured : $default;
    }
}
