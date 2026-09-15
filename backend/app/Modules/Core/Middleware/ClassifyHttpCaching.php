<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nothing is cacheable until it is classified (ADR 0036).
 *
 * Outermost in the API group, so it sees every API response — including the errors the
 * exception handler renders — after everything inside has had its say. A response a
 * cache profile decided on is left as that profile left it. Any other response is marked
 * `no-store`, with one exception: a controller that deliberately set `private` together
 * with a lifetime (a media file behind authentication, validated by its checksum) has
 * made a decision that keeps shared caches out already, and is left alone.
 *
 * A response that claims `public` without a profile is not trusted. That is how an
 * authenticated payload ends up in an edge node, and the fix is to classify the route.
 */
class ClassifyHttpCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($request->attributes->get(ApplyHttpCachePolicy::CLASSIFIED) === true) {
            return $response;
        }

        $headers = $response->headers;
        $deliberatelyPrivate = $headers->hasCacheControlDirective('private')
            && ! $headers->hasCacheControlDirective('public')
            && ($headers->hasCacheControlDirective('max-age') || $headers->hasCacheControlDirective('immutable'));

        if ($deliberatelyPrivate) {
            $headers->remove('CDN-Cache-Control');

            return $response;
        }

        return ApplyHttpCachePolicy::noStore($response);
    }
}
