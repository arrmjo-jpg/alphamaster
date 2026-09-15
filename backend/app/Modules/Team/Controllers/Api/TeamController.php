<?php

declare(strict_types=1);

namespace App\Modules\Team\Controllers\Api;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Delivery\EdgeCacheTag;
use App\Modules\Core\Http\Cache\ResponseCacheTags;
use App\Modules\Core\Requests\PublicContentRequest;
use App\Modules\Team\Resources\PublicTeamMemberResource;
use App\Modules\Team\Services\TeamLookup;
use App\Modules\Team\Services\TeamReader;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * The team directory, as anyone may read it (ADR 0055 §5). The language is required, and a
 * profile not available in it is a 404 that says which languages it is available in.
 */
class TeamController extends BaseApiController
{
    public function __construct(
        private readonly TeamReader $team,
        private readonly ContentLocales $locales,
        private readonly ResponseCacheTags $tags,
    ) {}

    /**
     * Active members with a profile in this language, in order.
     */
    #[Response(200, type: 'array{success: bool, data: list<PublicTeamMemberResource>}')]
    #[Response(404, description: 'CONTENT_LOCALE_NOT_SERVED.')]
    public function index(PublicContentRequest $request): JsonResponse
    {
        $locale = $request->contentLocale();

        if (! $this->locales->isServed($locale)) {
            return $this->notServed($locale);
        }

        $this->tags->add(EdgeCacheTag::for('team', 'list'));

        return $this->successResponse(
            $this->team->listIn($locale)->map(fn ($member) => new PublicTeamMemberResource($member, $locale, withProfile: false))->all(),
        );
    }

    /**
     * One member's profile in a language. Answered 301 for an old address, or another language's, when the profile exists in this language.
     */
    #[Response(200, type: 'array{success: bool, data: PublicTeamMemberResource}')]
    #[Response(301, description: 'The profile is at another address in this language: the Location header and data.redirect name it.')]
    #[Response(404, description: 'CONTENT_LOCALE_NOT_SERVED; CONTENT_NOT_AVAILABLE_IN_LOCALE, with details.available_locales; or NOT_FOUND.')]
    public function show(PublicContentRequest $request, string $slug): JsonResponse
    {
        $locale = $request->contentLocale();

        if (! $this->locales->isServed($locale)) {
            return $this->notServed($locale);
        }

        $lookup = $this->team->lookup($slug, $locale);

        if ($lookup->kind === TeamLookup::FOUND && $lookup->member !== null) {
            $this->tags->add(EdgeCacheTag::for('team', $lookup->member->id));

            return $this->successResponse(new PublicTeamMemberResource($lookup->member, $locale));
        }

        if ($lookup->kind === TeamLookup::REDIRECT) {
            $location = '/api/v1/team/'.rawurlencode((string) $lookup->slug).'?locale='.rawurlencode($locale);

            return $this->successResponse(['redirect' => ['locale' => $locale, 'slug' => $lookup->slug, 'location' => $location]], null, 301)
                ->header('Location', $location);
        }

        if ($lookup->kind === TeamLookup::UNAVAILABLE) {
            return $this->errorResponse(
                'CONTENT_NOT_AVAILABLE_IN_LOCALE',
                'api.error.content.not_available_in_locale',
                ['locale' => $locale, 'available_locales' => $lookup->availableLocales],
                404,
                ['locale' => $locale],
            );
        }

        return $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404);
    }

    private function notServed(string $locale): JsonResponse
    {
        return $this->errorResponse('CONTENT_LOCALE_NOT_SERVED', 'api.error.content.locale_not_served', ['locale' => $locale], 404, ['locale' => $locale]);
    }
}
