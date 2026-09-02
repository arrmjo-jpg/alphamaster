<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request and set the active application locale.
     *
     * Core does not hardcode supported languages. It safely applies requested locale
     * syntax or falls back to config('app.locale'). Dynamic database-driven
     * language resolution is deferred to Phase 3 Localization.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestedLocale = $request->header('X-Locale') ?? $request->query('locale');

        if (is_string($requestedLocale) && preg_match('/^[a-z]{2,3}(?:[_-][a-zA-Z0-9]+)?$/', $requestedLocale)) {
            app()->setLocale($requestedLocale);
        } else {
            app()->setLocale((string) config('app.locale', 'en'));
        }

        return $next($request);
    }
}
