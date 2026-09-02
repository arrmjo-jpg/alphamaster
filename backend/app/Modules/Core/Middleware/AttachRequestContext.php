<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttachRequestContext
{
    /**
     * Handle an incoming request, attach request_id to Laravel Context, and propagate to response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-ID')
            ?? $request->header('X-Correlation-ID')
            ?? (string) Str::ulid();

        // Bind request_id to native Laravel Context
        Context::add('request_id', $requestId);

        if ($request->user()) {
            Context::add('actor_id', (string) $request->user()->getAuthIdentifier());
        }

        $response = $next($request);

        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
