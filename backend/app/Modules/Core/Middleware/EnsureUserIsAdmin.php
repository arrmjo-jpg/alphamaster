<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use Closure;
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
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required to access administrative endpoints.',
                ],
            ], 401);
        }

        // Administrative identity must be explicitly established (Fail Closed)
        $isAdmin = false;

        if (isset($user->is_admin) && (bool) $user->is_admin) {
            $isAdmin = true;
        } elseif (method_exists($user, 'hasRole') && ($user->hasRole('admin') || $user->hasRole('super-admin'))) {
            $isAdmin = true;
        }

        if (! $isAdmin) {
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
