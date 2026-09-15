<?php

declare(strict_types=1);

namespace App\Modules\Pages\Controllers\Api;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Delivery\EdgeCacheTag;
use App\Modules\Core\Http\Cache\ResponseCacheTags;
use App\Modules\Core\Requests\PublicContentRequest;
use App\Modules\Pages\Resources\PublicPageResource;
use App\Modules\Pages\Services\PageLookup;
use App\Modules\Pages\Services\PageReader;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Static pages, as anyone may read them (ADR 0055 §5).
 *
 * The language is required and is the only thing that chooses the content. A language that
 * is not served, or a page not available in the language asked for, is a 404 that says so;
 * nothing is substituted from another language.
 */
class PageController extends BaseApiController
{
    public function __construct(
        private readonly PageReader $pages,
        private readonly ContentLocales $locales,
        private readonly ResponseCacheTags $tags,
    ) {}

    /**
     * Pages available in a language, in order.
     */
    #[Response(200, type: 'array{success: bool, data: list<PublicPageResource>}')]
    #[Response(404, description: 'CONTENT_LOCALE_NOT_SERVED: the language is not one the platform serves.')]
    public function index(PublicContentRequest $request): JsonResponse
    {
        $locale = $request->contentLocale();

        if (! $this->locales->isServed($locale)) {
            return $this->notServed($locale);
        }

        $this->tags->add(EdgeCacheTag::for('pages', 'list'));

        return $this->successResponse(
            $this->pages->listIn($locale)->map(fn ($page) => new PublicPageResource($page, $locale, withBody: false))->all(),
        );
    }

    /**
     * One page in a language.
     *
     * Answered 301 when the address is an old one, or another language's, for a page available in this language.
     */
    #[Response(200, type: 'array{success: bool, data: PublicPageResource}')]
    #[Response(301, description: 'The page is available in this language at another address: the Location header and data.redirect name it.')]
    #[Response(404, description: 'CONTENT_LOCALE_NOT_SERVED; CONTENT_NOT_AVAILABLE_IN_LOCALE, with details.available_locales; or NOT_FOUND.')]
    public function show(PublicContentRequest $request, string $slug): JsonResponse
    {
        $locale = $request->contentLocale();

        if (! $this->locales->isServed($locale)) {
            return $this->notServed($locale);
        }

        $lookup = $this->pages->lookup($slug, $locale);

        return match ($lookup->kind) {
            PageLookup::FOUND => $this->found($lookup, $locale),
            PageLookup::REDIRECT => $this->redirect((string) $lookup->slug, $locale),
            PageLookup::UNAVAILABLE => $this->errorResponse(
                'CONTENT_NOT_AVAILABLE_IN_LOCALE',
                'api.error.content.not_available_in_locale',
                ['locale' => $locale, 'available_locales' => $lookup->availableLocales],
                404,
                ['locale' => $locale],
            ),
            default => $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404),
        };
    }

    private function found(PageLookup $lookup, string $locale): JsonResponse
    {
        $page = $lookup->page;
        assert($page !== null);

        $this->tags->add(EdgeCacheTag::for('pages', $page->id));

        return $this->successResponse(new PublicPageResource($page, $locale));
    }

    private function redirect(string $slug, string $locale): JsonResponse
    {
        $location = '/api/v1/pages/'.rawurlencode($slug).'?locale='.rawurlencode($locale);

        return $this->successResponse(['redirect' => ['locale' => $locale, 'slug' => $slug, 'location' => $location]], null, 301)
            ->header('Location', $location);
    }

    private function notServed(string $locale): JsonResponse
    {
        return $this->errorResponse('CONTENT_LOCALE_NOT_SERVED', 'api.error.content.locale_not_served', ['locale' => $locale], 404, ['locale' => $locale]);
    }
}
