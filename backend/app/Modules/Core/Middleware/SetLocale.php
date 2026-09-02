<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use App\Modules\Core\Contracts\LocaleResolverInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request and set the active application locale and direction.
     *
     * Core relies on the injected LocaleResolverInterface without importing or coupling
     * to any specific domain implementation or hardcoding languages.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->bound(LocaleResolverInterface::class)) {
            /** @var LocaleResolverInterface $resolver */
            $resolver = app(LocaleResolverInterface::class);
            $locale = $resolver->resolve($request);
            $direction = $resolver->getDirection($locale);
        } else {
            $locale = (string) config('app.locale', 'en');
            $direction = 'ltr';
        }

        app()->setLocale($locale);

        $response = $next($request);

        $response->headers->set('Content-Language', $locale);
        $response->headers->set('X-Direction', $direction);

        return $response;
    }
}
