<?php

declare(strict_types=1);

namespace App\Modules\Pages\Controllers\Admin;

use App\Modules\Core\Content\ContentRefusedException;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Pages\Models\Page;
use App\Modules\Pages\Requests\PublishPageRequest;
use App\Modules\Pages\Requests\StorePageRequest;
use App\Modules\Pages\Requests\WritePageTranslationRequest;
use App\Modules\Pages\Resources\PageAdminResource;
use App\Modules\Pages\Services\PageService;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Static pages in the Admin (ADR 0055).
 *
 * The content language is always named: in the path when writing, and it has nothing to do
 * with `X-Locale`, which only chooses the language of the labels and messages returned.
 */
class PageAdminController extends BaseApiController
{
    public function __construct(private readonly PageService $pages) {}

    /**
     * Every page, with its translation progress in each language.
     */
    #[Response(200, type: 'array{success: bool, data: list<PageAdminResource>}')]
    public function index(): JsonResponse
    {
        $pages = Page::query()->with('translations')->orderBy('sort_order')->orderBy('created_at')->get();

        return $this->successResponse(PageAdminResource::collection($pages));
    }

    /**
     * Create a page. It starts as a draft with no text; write its translations next.
     */
    #[Response(201, type: 'array{success: bool, message: string, data: PageAdminResource}')]
    public function store(StorePageRequest $request): JsonResponse
    {
        $page = $this->pages->create((int) $request->validated('sort_order', 0), $this->actor($request));

        return $this->successResponse(new PageAdminResource($page->load('translations')), 'api.pages.created', 201);
    }

    #[Response(200, type: 'array{success: bool, data: PageAdminResource}')]
    public function show(Page $page): JsonResponse
    {
        return $this->successResponse(new PageAdminResource($page->load('translations')));
    }

    /**
     * Change what is the same in every language.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: PageAdminResource}')]
    public function update(StorePageRequest $request, Page $page): JsonResponse
    {
        if ($request->has('sort_order')) {
            $page = $this->pages->reorder($page, (int) $request->validated('sort_order'), $this->actor($request));
        }

        return $this->successResponse(new PageAdminResource($page->load('translations')), 'api.pages.updated');
    }

    /**
     * Write one language's text, address and SEO. The language is any Language Management knows, served or not.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: PageAdminResource}')]
    #[Response(409, description: 'CONTENT_SLUG_TAKEN, or CONTENT_DEFAULT_TRANSLATION_REQUIRED when the change would leave a published page without a complete default-language translation.')]
    #[Response(422, description: 'UNKNOWN_CONTENT_LOCALE, CONTENT_SLUG_INVALID or CONTENT_IMAGE_UNAVAILABLE.')]
    public function writeTranslation(WritePageTranslationRequest $request, Page $page, string $locale): JsonResponse
    {
        try {
            $this->pages->writeTranslation($page, $locale, $request->content(), $request->seo(), $this->actor($request));
        } catch (ContentRefusedException $e) {
            return $this->refused($e);
        }

        return $this->successResponse(new PageAdminResource($page->refresh()->load('translations')), 'api.pages.translation_saved');
    }

    /**
     * Publish. Needs a complete translation in the default language; other languages may be missing.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: PageAdminResource}')]
    #[Response(409, description: 'CONTENT_DEFAULT_TRANSLATION_INCOMPLETE: details name the default language.')]
    public function publish(PublishPageRequest $request, Page $page): JsonResponse
    {
        $at = $request->validated('published_at');

        try {
            $page = $this->pages->publish($page, is_string($at) ? Carbon::parse($at) : null, $this->actor($request));
        } catch (ContentRefusedException $e) {
            return $this->refused($e);
        }

        return $this->successResponse(new PageAdminResource($page->load('translations')), 'api.pages.published');
    }

    #[Response(200, type: 'array{success: bool, message: string, data: PageAdminResource}')]
    public function unpublish(Request $request, Page $page): JsonResponse
    {
        $page = $this->pages->unpublish($page, $this->actor($request));

        return $this->successResponse(new PageAdminResource($page->load('translations')), 'api.pages.unpublished');
    }

    #[Response(200, type: 'array{success: bool, message: string, data: PageAdminResource}')]
    public function archive(Request $request, Page $page): JsonResponse
    {
        $page = $this->pages->archive($page, $this->actor($request));

        return $this->successResponse(new PageAdminResource($page->load('translations')), 'api.pages.archived');
    }

    /**
     * Delete a page, its translations, its SEO and its old addresses.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: null}')]
    public function destroy(Page $page): JsonResponse
    {
        $this->pages->delete($page);

        return $this->successResponse(null, 'api.pages.deleted');
    }

    private function refused(ContentRefusedException $e): JsonResponse
    {
        return $this->errorResponse(
            $e->errorCode,
            $e->messageKey,
            $e->details === [] ? null : $e->details,
            $e->status,
            array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $e->details),
        );
    }

    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
