<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Maintenance mode, as ADR 0018 decided it.
 *
 * `general.maintenance_mode` has been a configurable setting since Phase 4 and nothing
 * has ever read it: an operator could switch the platform into maintenance and the
 * platform would carry on serving. A setting that describes an intention the platform
 * ignores is worse than no setting, because it is a control that looks like it works.
 *
 * The behaviour is not invented here. ADR 0018 states it: while maintenance is active
 * the API answers 503 with the platform error envelope and a localized message, never
 * a framework HTML page, and an administrator holding `admin:access` bypasses it when
 * the bypass flag is set — so the platform can be repaired through its own interface.
 *
 * Three consequences worth stating rather than leaving to be discovered.
 *
 * The bypass is the token's ability, not the account's type. `admin:access` is the
 * perimeter ADR 0012 draws, and it is what an administrator's session actually
 * carries; an account that is an administrator but is calling with a `user:access`
 * token is not administering anything at that moment.
 *
 * With the bypass switched off, nobody gets in — including the administrator who
 * switched it off. That is what the setting means, and the way back is the console or
 * the database rather than the API. It is a deliberate configuration and the help text
 * for the setting says so.
 *
 * Nothing is exempt, including `/health`. The container healthchecks probe nginx's own
 * stub rather than this API, so a platform in maintenance reports itself unavailable
 * without any orchestrator concluding the process has died.
 *
 * And if the setting cannot be read at all, the platform stays open. That is the same
 * choice `ApplyRateLimit` makes for the same reason: this is an operational switch and
 * no boundary depends on it, so a settings outage must not become a total outage. It
 * is logged rather than swallowed, because a maintenance switch that cannot be read is
 * a state somebody needs to know about.
 */
class EnsureNotInMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $closed = setting('general.maintenance_mode', false) === true;
        } catch (Throwable $e) {
            Log::warning('Maintenance setting unreadable; request allowed through.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $next($request);
        }

        if (! $closed) {
            return $next($request);
        }

        if ($this->mayBypass($request)) {
            return $next($request);
        }

        $configured = setting('general.maintenance_message');

        return response()->json([
            'success' => false,
            'error' => [
                // `code` is contract and is never localized (ADR 0031).
                'code' => 'MAINTENANCE_MODE',
                'message' => is_string($configured) && trim($configured) !== ''
                    ? $configured
                    : __('api.error.maintenance_mode'),
            ],
        ], 503);
    }

    /**
     * Whether this caller may work while the platform is closed.
     *
     * Resolved through the guard rather than read from a header, so the cookie
     * transport (ADR 0042) and a bearer token answer the same way — both end up as a
     * real personal access token whose abilities mean what they say.
     */
    private function mayBypass(Request $request): bool
    {
        if (setting('general.maintenance_admin_bypass', true) !== true) {
            return false;
        }

        // The guard is named rather than defaulted. This middleware runs in the API
        // group, before any route's `auth:sanctum` has resolved anybody, and the
        // default guard is the session one — which answers null for a token request
        // and would refuse every administrator the bypass exists for.
        $user = $request->user('sanctum');

        return $user !== null
            && method_exists($user, 'tokenCan')
            && $user->tokenCan('admin:access');
    }
}
