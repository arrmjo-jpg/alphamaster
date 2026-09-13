<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use App\Modules\Core\Services\RateLimitPolicy;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Meters requests refused at authentication (ADR 0046).
 *
 * The central limiter is `api`-group middleware, and Laravel sorts `Authenticate` ahead
 * of the group — so a request with a missing, expired or forged token is answered 401
 * before the limiter runs, and those were unlimited (ADR 0029 item 22).
 *
 * This runs globally, around routing, and acts only *after* the request was refused:
 * the exception renderer marks the request when authentication rejects it, and this
 * counts the mark against the address. Once an address is over the anonymous ceiling,
 * the refusals it goes on provoking are answered 429 with a Retry-After instead of 401.
 *
 * What it deliberately does not do is refuse anything *before* authentication. A limiter
 * there has only the address to go on, and would let one hostile caller behind an
 * office's address lock every authenticated colleague behind it out of the platform. A
 * request that authenticates is never touched by this; a request that did not was going
 * to be refused anyway, and still is — the only thing that changes is which refusal it
 * receives. Nothing here can turn a refusal into an admission, so the platform's
 * authentication stays exactly as fail-closed as it was.
 *
 * A failed *login* is not counted: that is a wrong password, not a rejected credential,
 * and it has its own identifier throttle and its own class ceiling already.
 */
class LimitRejectedAuthentication
{
    /**
     * The request attribute the authentication renderer sets. Namespaced, because a
     * request's attributes are shared with everything else that runs on it.
     */
    public const REJECTED = 'alphamaster.authentication_rejected';

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly RateLimitPolicy $policy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->attributes->get(self::REJECTED) !== true) {
            return $response;
        }

        // A caller that failed authentication is anonymous, so it shares the
        // anonymous ceiling rather than having one of its own — the setting an
        // operator already reads as "what one address may do unauthenticated".
        $max = $this->policy->maxAttempts(RateLimitPolicy::PUBLIC_READ);
        // Hashed for the reason every limiter key here is: a cache is not a place to
        // keep client addresses in clear.
        $key = 'rejected-auth:ip:'.sha1((string) $request->ip());

        try {
            if ($this->limiter->tooManyAttempts($key, $max)) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, [
                    'Retry-After' => $this->limiter->availableIn($key),
                    'X-RateLimit-Limit' => $max,
                    'X-RateLimit-Remaining' => 0,
                ]);
            }

            $this->limiter->hit($key, self::DECAY_SECONDS);
        } catch (ThrottleRequestsException $e) {
            throw $e;
        } catch (Throwable $e) {
            // The count lives in Redis. Without it the refusal still stands — it is
            // a 401 either way — so an outage costs the metering, never the refusal.
            Log::warning('Rejected-authentication limiter unavailable; the refusal stands unmetered.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $response;
    }
}
