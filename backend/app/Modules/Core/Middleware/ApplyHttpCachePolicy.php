<?php

declare(strict_types=1);

namespace App\Modules\Core\Middleware;

use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Delivery\EdgeInvalidationKind;
use App\Modules\Core\Http\Cache\HttpCacheProfileRegistry;
use App\Modules\Core\Http\Cache\ResponseCacheTags;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a public response cacheable, under a declared profile, when it is safe to (ADR 0036).
 *
 * Applied per route: `->middleware('http.cache:public-configuration,settings:public')` names
 * the profile and any edge cache tags the route always carries. Everything else about the
 * decision is made here, from the request and the response actually produced:
 *
 * * **Only an anonymous, successful GET or HEAD is ever public.** A request carrying an
 *   Authorization header, a cookie, or a resolved user gets `no-store` whatever the
 *   profile says — the response may vary by identity, and ADR 0036 answers that one by not
 *   caching at all rather than with `Vary: Authorization`. So does any status but 200, and
 *   any response setting a cookie.
 * * **A localized payload reaches the edge only when its language is in the URL.** The
 *   locale can also come from `X-Locale` or `Accept-Language`, which an edge does not
 *   reliably key on. Those responses stay cacheable in the browser, with `Vary`, and are
 *   marked `no-store` for the edge.
 * * **The browser and the edge get separate lifetimes**: `Cache-Control` for the first,
 *   `CDN-Cache-Control` (RFC 9213) for the second. The standard header rather than a
 *   vendor's, so no CDN driver is named here.
 * * **The validator is derived from the bytes and the language**, so two representations
 *   never share one, and a matching `If-None-Match` is answered with 304.
 * * **Tags are written only for a response the edge may store**, in the header the
 *   configured edge reads. With no edge configured, no tag header is sent.
 */
class ApplyHttpCachePolicy
{
    /** Set on the request so the classifier knows this response was decided here. */
    public const CLASSIFIED = 'http_cache.classified';

    public function __construct(
        private readonly HttpCacheProfileRegistry $profiles,
        private readonly ResponseCacheTags $tags,
        private readonly EdgeCacheContract $edge,
    ) {}

    public function handle(Request $request, Closure $next, string $profile, string ...$routeTags): Response
    {
        $definition = $this->profiles->find($profile)
            ?? throw new LogicException("HTTP cache profile [{$profile}] is not registered.");

        $request->attributes->set(self::CLASSIFIED, true);

        /** @var Response $response */
        $response = $next($request);

        if (! $this->storable($request, $response)) {
            return self::noStore($response);
        }

        $locale = app()->getLocale();
        $etag = 'W/"'.substr(hash('sha256', $locale.'|'.(string) $response->getContent()), 0, 32).'"';
        $edgeAllowed = ! $definition->localized || $this->localeIsInTheAddress($request, $locale);

        $response->headers->set('Cache-Control', $definition->browserDirectives());
        $response->headers->set('CDN-Cache-Control', $edgeAllowed ? $definition->edgeDirectives() : 'no-store');
        $response->headers->set('ETag', $etag);

        if ($definition->localized) {
            // One header line, merged with anything already varied on: some caches read
            // only the first Vary line they see.
            $vary = array_values(array_unique([...$response->getVary(), 'Accept-Language', 'X-Locale']));
            $response->headers->set('Vary', implode(', ', $vary));
        }

        if ($edgeAllowed) {
            $this->writeTags($response, $routeTags);
        }

        if ($this->clientHolds($request, $etag)) {
            $response->setNotModified();
        }

        return $response;
    }

    /**
     * Mark a response as never to be written down by any cache.
     *
     * A validator the application set is left in place. `no-store` already stops every
     * cache from keeping the response, and an ETag on an authenticated read is how a
     * client carries the version a later write needs (ADR 0038) — removing it would
     * break optimistic concurrency without making anything more private.
     */
    public static function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->remove('CDN-Cache-Control');

        return $response;
    }

    private function storable(Request $request, Response $response): bool
    {
        return in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && $response->getStatusCode() === 200
            && ! $response->headers->has('Set-Cookie')
            && ! $this->carriesIdentity($request);
    }

    /**
     * Anything that could make this response one person's.
     */
    private function carriesIdentity(Request $request): bool
    {
        return $request->headers->has('Authorization')
            || $request->cookies->count() > 0
            || $request->user() !== null;
    }

    /**
     * Whether the negotiated language came from the query string, and nothing else.
     *
     * A `locale` parameter the platform did not honour (an inactive language, say) means
     * the language came from somewhere the address does not show.
     */
    private function localeIsInTheAddress(Request $request, string $locale): bool
    {
        $query = $request->query('locale');

        return is_string($query)
            && ! $request->headers->has('X-Locale')
            && strtolower(trim($query)) === strtolower($locale);
    }

    /**
     * @param  array<int, string>  $routeTags
     */
    private function writeTags(Response $response, array $routeTags): void
    {
        $header = $this->edge->tagHeader();

        if ($header === null) {
            return;
        }

        $tags = array_values(array_unique(array_filter(
            [...$routeTags, ...$this->tags->all()],
            static fn (string $tag): bool => EdgeInvalidation::problem(EdgeInvalidationKind::TAGS, $tag) === null,
        )));

        if ($tags !== []) {
            $response->headers->set($header, implode(',', $tags));
        }
    }

    private function clientHolds(Request $request, string $etag): bool
    {
        $presented = array_map('trim', explode(',', (string) $request->headers->get('If-None-Match', '')));

        return in_array($etag, $presented, true);
    }
}
