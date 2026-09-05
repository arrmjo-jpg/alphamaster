<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use App\Modules\Core\Services\RateLimitPolicy;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Applies the central limiter, choosing the class from the route.
 *
 * Laravel's own `ThrottleRequests` does the counting and produces the headers;
 * this only decides which named limiter it should use, so the class lives with
 * the route rather than being repeated at 46 call sites.
 */
class ApplyRateLimit extends ThrottleRequests
{
    /**
     * Never limited. `/api/v1/health` reports whether the application answers at
     * all, and a monitor polling it must not be told to come back later.
     *
     * @var list<string>
     */
    private const EXEMPT = ['api.health'];

    /**
     * Endpoints that already carry their own, far tighter throttle. The class
     * ceiling here is an outer bound on an attacker rotating identifiers, which
     * LoginThrottle cannot see because its key includes the identifier.
     *
     * @var list<string>
     */
    private const AUTH_ROUTES = [
        'api.auth.login',
        'api.auth.mfa.challenge',
        'api.auth.mfa.challenge.send',
    ];

    /**
     * Accepting bytes, validating them synchronously, writing to disk and
     * enqueuing a scan costs more than any other endpoint, so it gets its own
     * budget rather than competing with ordinary writes.
     *
     * @var list<string>
     */
    private const UPLOAD_ROUTES = ['api.media.store'];

    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        $class = $this->rateLimitClass($request);

        if ($class === null) {
            return $next($request);
        }

        try {
            // Exactly three arguments: that is what makes the parent treat the
            // third as the name of a registered limiter.
            return parent::handle($request, $next, $class);
        } catch (ThrottleRequestsException $e) {
            // A real rejection. It carries the headers the renderer reports.
            throw $e;
        } catch (Throwable $e) {
            // The limiter counts in Redis. If Redis is unreachable the count
            // cannot be read, and the choice is between refusing every request
            // and admitting them unmetered. Rate limiting is a mitigation, not an
            // authorization control — no boundary in this platform depends on it
            // — so a cache outage must not become a total outage. It is logged
            // rather than swallowed, because unmetered traffic is a state someone
            // needs to know about.
            Log::warning('Rate limiter unavailable; request allowed unmetered.', [
                'class' => $class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $next($request);
        }
    }

    /**
     * The class this request is counted against, or null when it is exempt.
     */
    private function rateLimitClass(Request $request): ?string
    {
        $name = $request->route()?->getName();

        if ($name !== null && in_array($name, self::EXEMPT, true)) {
            return null;
        }

        if ($name !== null && in_array($name, self::AUTH_ROUTES, true)) {
            return RateLimitPolicy::AUTH;
        }

        // Authentication middleware is sorted ahead of this group by Laravel's
        // middleware priority, so a user is already resolved here when the route
        // requires one.
        if ($request->user() === null) {
            return RateLimitPolicy::PUBLIC_READ;
        }

        if ($name !== null && in_array($name, self::UPLOAD_ROUTES, true)) {
            return RateLimitPolicy::UPLOAD;
        }

        return $request->isMethodSafe() ? RateLimitPolicy::READ : RateLimitPolicy::WRITE;
    }
}
