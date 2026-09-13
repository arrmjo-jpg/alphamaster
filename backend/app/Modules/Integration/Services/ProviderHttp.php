<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP client every vendor driver uses, carrying the platform's timeout and its
 * retry policy (ADR 0047).
 *
 * **Retry means only one thing here: the connection never opened.** The host did not
 * resolve, or the vendor refused the connection. In both cases no byte of the request
 * reached the vendor, so sending it again cannot deliver it twice. Everything else is
 * handed straight to the failover chain (ADR 0017) without a second attempt at the same
 * vendor:
 *
 * - a **timeout**, because curl reports a connect timeout and a read timeout with the
 *   same code, and a read timeout is the vendor holding a request it may already have
 *   acted on — an SMS that times out after the vendor queued it would become two;
 * - **any response the vendor returned**, including a 500, because an answer means the
 *   request arrived, and whether a vendor's 500 left a message queued is not knowable
 *   from outside;
 * - an **SSL handshake failure**, which is safe to repeat but is a configuration fault
 *   that repeating would only delay reporting.
 *
 * None of the vendors this platform integrates accepts an idempotency key on the calls
 * it makes, so the ambiguous cases cannot be made safe by deduplication at the vendor.
 * They are not retried at all rather than retried hopefully.
 */
final class ProviderHttp
{
    /**
     * curl's codes for a connection that was never established: could not resolve the
     * proxy (5), could not resolve the host (6), could not connect (7).
     */
    private const NEVER_CONNECTED = [5, 6, 7];

    private const DEFAULT_TIMEOUT_SECONDS = 10;

    private const DEFAULT_RETRIES = 2;

    private const MAX_RETRIES = 10;

    /**
     * The wait before retry *n*, doubling from 100 ms and capped at one second: long
     * enough for a resolver blip to pass, short enough that ten retries of an OTP send
     * still answer in a few seconds rather than keep a person waiting on a login.
     */
    private const FIRST_BACKOFF_MS = 100;

    private const MAX_BACKOFF_MS = 1000;

    /**
     * A request to a vendor, with the platform's timeout and retry policy applied.
     *
     * @param  int|null  $timeoutSeconds  A capability that has its own ceiling (AI) passes it;
     *                                    everything else takes the operations setting.
     */
    public static function client(?int $timeoutSeconds = null): PendingRequest
    {
        return Http::timeout($timeoutSeconds ?? self::timeoutSeconds())
            ->retry(
                self::retries() + 1,
                static fn (int $attempt): int => self::backoffMilliseconds($attempt),
                static fn (Throwable $e): bool => self::neverConnected($e),
                // A vendor's error response is returned to the driver as a response,
                // which is how every driver already reads one. Only a connection
                // failure is thrown, after the last attempt.
                throw: false,
            );
    }

    /**
     * Whether a failure proves the vendor never received the request.
     */
    public static function neverConnected(Throwable $e): bool
    {
        // Only a transport failure is a candidate. The same words in any other
        // exception prove nothing about what reached the vendor.
        if (! $e instanceof ConnectionException) {
            return false;
        }

        return in_array(self::curlCode($e->getMessage()), self::NEVER_CONNECTED, true);
    }

    /**
     * Extra attempts after the first, from `operations.provider_retry_attempts`.
     *
     * Guarded the way every operational read is: a missing or out-of-range value falls
     * back to the setting's own default rather than to zero or to unbounded.
     */
    public static function retries(): int
    {
        $configured = setting('operations.provider_retry_attempts', self::DEFAULT_RETRIES);

        return is_int($configured) && $configured >= 0 && $configured <= self::MAX_RETRIES
            ? $configured
            : self::DEFAULT_RETRIES;
    }

    /**
     * How long to wait on a vendor, from `operations.provider_timeout_seconds`.
     */
    public static function timeoutSeconds(): int
    {
        $configured = setting('operations.provider_timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS);

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_TIMEOUT_SECONDS;
    }

    public static function backoffMilliseconds(int $attempt): int
    {
        return min(self::FIRST_BACKOFF_MS * (2 ** max(0, $attempt - 1)), self::MAX_BACKOFF_MS);
    }

    /**
     * The curl code in a transport failure's message.
     *
     * The message is the only place it is carried: Guzzle's curl handler writes
     * `cURL error N: …` into the exception it raises and keeps no structured copy, and
     * Laravel's ConnectionException passes that message through unchanged. No code, no
     * retry — an unrecognised failure is treated as one that may have reached the vendor.
     */
    private static function curlCode(string $message): ?int
    {
        return preg_match('/cURL error (\d+)/', $message, $match) === 1 ? (int) $match[1] : null;
    }
}
