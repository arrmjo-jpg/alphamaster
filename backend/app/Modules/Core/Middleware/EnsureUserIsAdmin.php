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

        // 1. Verify Sanctum token ability if request uses token-based auth
        if (method_exists($user, 'currentAccessToken') && $user->currentAccessToken()) {
            if (! $user->tokenCan('admin:access') && ! $user->tokenCan('*')) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'FORBIDDEN',
                        'message' => 'Token does not possess required administrative abilities.',
                    ],
                ], 403);
            }
        }

        // 2. Verify administrative identity attribute/role if present
        $isAdmin = false;
        if (isset($user->is_admin) && (bool) $user->is_admin) {
            $isAdmin = true;
        } elseif (method_exists($user, 'hasRole') && ($user->hasRole('admin') || $user->hasRole('super-admin'))) {
            $isAdmin = true;
        } elseif (! isset($user->is_admin) && ! method_exists($user, 'hasRole')) {
            // Fallback for base user during early scaffolding
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
