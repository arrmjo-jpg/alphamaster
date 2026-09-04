<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use App\Modules\Core\Contracts\AdminIdentity;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request and enforce the Admin perimeter boundary.
     *
     * The boundary fails closed: any request where administrative identity
     * cannot be strictly proven is denied with HTTP 403.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Typed as the framework's own contract rather than the concrete model.
        // Request::user() returns ?Authenticatable; static analysis narrows that to
        // whatever config/auth.php currently names, but that file reads
        // env('AUTH_MODEL', User::class) — so the concrete class is deployment
        // configuration, not a guarantee this middleware may rely on. A security
        // boundary that trusts the current configuration stops being a boundary the
        // day the configuration changes.
        /** @var Authenticatable|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required to access administrative endpoints.',
                ],
            ], 401);
        }

        // Administrative identity rests on the account type discriminator and nothing
        // else (Fail Closed). Deliberately no role or permission lookup happens here:
        // a role is what an administrator may do, never what makes them one, so a
        // regular user cannot become an administrator by acquiring a role relation.
        // Permission checks are a separate, later stage (EnsurePermission).
        //
        // The identity must declare AdminIdentity, not merely happen to carry a
        // method named isAdmin: method_exists() accepted any object with a method of
        // that name, whatever it meant there, which is a weaker claim than it looked.
        if (! $user instanceof AdminIdentity || ! $user->isAdmin()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ADMIN_ACCESS_REQUIRED',
                    'message' => 'Administrative privileges are required to access this endpoint.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
