<?php

declare(strict_types=1);

namespace App\Modules\Core\Controllers\Api;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Http\Cache\ResponseCacheTags;
use App\Modules\Core\Seo\RobotsTxt;
use App\Modules\Core\Seo\Sitemap\SitemapRenderer;
use Dedoc\Scramble\Attributes\Response as ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The public site's robots.txt and sitemap, served by the API (ADR 0058 §2, §3).
 *
 * The platform is API-only, so these live under `/api/v1`; the frontend or the edge serves them
 * at the site's root — `/robots.txt`, `/sitemap.xml` and `/sitemaps/...` — which is the only
 * mapping it owns. Every address inside them is composed by Core on the public origin.
 */
class SeoController extends BaseApiController
{
    public function __construct(
        private readonly SitemapRenderer $sitemap,
        private readonly RobotsTxt $robots,
        private readonly ResponseCacheTags $tags,
    ) {}

    /**
     * The public site's robots.txt.
     *
     * In production it allows crawling, excludes the administrative API, adds the operator's extra directives and names the sitemap. In every other environment it disallows everything.
     */
    #[ResponseShape(status: 200, description: 'The robots.txt document.', mediaType: 'text/plain', type: 'string')]
    public function robots(): Response
    {
        return response($this->robots->render(app()->isProduction()), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * The sitemap index.
     *
     * Names every sitemap file at the public origin. Answered 404 when no public origin is configured, because a sitemap of relative addresses is not one.
     */
    #[ResponseShape(status: 200, description: 'The sitemap index document.', mediaType: 'application/xml', type: 'string')]
    #[ResponseShape(status: 404, description: 'NOT_FOUND: no public origin is configured.')]
    public function index(): Response|JsonResponse
    {
        $xml = $this->sitemap->index();

        if ($xml === null) {
            return $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404);
        }

        $this->tags->add(SitemapRenderer::indexTag());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * One sitemap file.
     *
     * One source's indexable addresses, at most 50,000 to a file, each with its alternates in every language it is published in.
     */
    #[ResponseShape(status: 200, description: 'The sitemap document.', mediaType: 'application/xml', type: 'string')]
    #[ResponseShape(status: 404, description: 'NOT_FOUND: no such source or file, or no public origin is configured.')]
    public function file(string $source, string $file): Response|JsonResponse
    {
        $xml = $this->sitemap->file($source, (int) $file);

        if ($xml === null) {
            return $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404);
        }

        $this->tags->add(SitemapRenderer::sourceTag($source));

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
