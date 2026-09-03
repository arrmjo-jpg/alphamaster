<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Middleware;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\User\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authorization stage: an administrator still needs the specific permission.
 *
 * Runs after the perimeter has established that the caller is authenticated, active,
 * carries admin:access, and is an admin account. Being an administrator is necessary
 * but never sufficient — super-admin behaviour is an explicit role with explicit
 * permissions, never a consequence of account_type alone.
 */
class EnsurePermission
{
    public function __construct(private readonly AdminRbacContract $rbac) {}

    /**
     * Requires every listed permission.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->deny('UNAUTHENTICATED', 'Authentication is required to access this endpoint.', 401);
        }

        // Fails closed, and re-checks the account type rather than trusting that an
        // earlier stage ran: this middleware must be safe wherever it is mounted.
        if (! $this->rbac->participates($user)) {
            return $this->deny(
                'ADMIN_ACCESS_REQUIRED',
                'Administrative privileges are required to access this endpoint.',
                403
            );
        }

        foreach ($permissions as $permission) {
            if (! $this->rbac->allows($user, $permission)) {
                return $this->deny(
                    'PERMISSION_DENIED',
                    "This action requires the [{$permission}] permission.",
                    403
                );
            }
        }

        return $next($request);
    }

    private function deny(string $code, string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
