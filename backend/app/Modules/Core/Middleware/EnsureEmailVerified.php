<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The fifth stage of the administrative perimeter (ADR 0012): a verified address.
 *
 * Issuance already refuses to mint admin:access for an administrator who has not
 * verified, so in ordinary operation no token reaching here can belong to one. This
 * stage exists for the case that is not ordinary — a token minted before the rule
 * existed, an address that changed after a token was issued, a future path that mints
 * one without going through sign-in. A perimeter made of one check is a perimeter
 * that is exactly as correct as the last person to touch the issuing code.
 *
 * Fails closed in both directions. An identity that cannot answer the question at all
 * — one that does not implement the framework's MustVerifyEmail contract — is refused
 * rather than waved through, for the same reason EnsureUserIsAdmin refuses an identity
 * that does not declare AdminIdentity: an unanswerable question is not a yes.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        // Typed as the framework's own contract rather than the concrete model, for
        // the reason EnsureUserIsAdmin states at length: static analysis narrows
        // Request::user() to whatever config/auth.php currently names, and that file
        // reads env('AUTH_MODEL', User::class) — so the concrete class is deployment
        // configuration, not a guarantee. Without this the instanceof below is
        // reported as always true, which is a statement about today's configuration
        // rather than about what this middleware may rely on.
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

        if (! $user instanceof MustVerifyEmail || ! $user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EMAIL_VERIFICATION_REQUIRED',
                    'message' => 'Verify your email address to access administrative endpoints.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
