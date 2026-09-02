<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    /**
     * Handle an incoming request and ensure user account is active.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            // Check if model has is_active or status attribute
            if (isset($user->is_active) && ! $user->is_active) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'ACCOUNT_SUSPENDED',
                        'message' => 'Your account has been suspended or deactivated.',
                    ],
                ], 403);
            }

            if (isset($user->status) && $user->status === 'suspended') {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'ACCOUNT_SUSPENDED',
                        'message' => 'Your account has been suspended or deactivated.',
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
