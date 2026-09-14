<?php

declare(strict_types=1);

namespace App\Modules\Team\Controllers\Admin;

use App\Modules\Core\Content\ContentRefusedException;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Team\Models\TeamMember;
use App\Modules\Team\Requests\StoreTeamMemberRequest;
use App\Modules\Team\Requests\WriteTeamMemberTranslationRequest;
use App\Modules\Team\Resources\TeamMemberAdminResource;
use App\Modules\Team\Services\TeamService;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The team directory in the Admin (ADR 0055). The content language is named in the path;
 * `X-Locale` only chooses the language of labels and messages.
 */
class TeamMemberAdminController extends BaseApiController
{
    public function __construct(private readonly TeamService $team) {}

    #[Response(200, type: 'array{success: bool, data: list<TeamMemberAdminResource>}')]
    public function index(): JsonResponse
    {
        $members = TeamMember::query()->with('translations')->orderBy('sort_order')->orderBy('created_at')->get();

        return $this->successResponse(TeamMemberAdminResource::collection($members));
    }

    /**
     * Add a member. They start inactive with no profile; write their translations next.
     */
    #[Response(201, type: 'array{success: bool, message: string, data: TeamMemberAdminResource}')]
    #[Response(422, description: 'CONTENT_IMAGE_UNAVAILABLE: the avatar is not a public image ready to serve.')]
    public function store(StoreTeamMemberRequest $request): JsonResponse
    {
        try {
            $member = $this->team->create($request->sharedChanges(), $this->actor($request));
        } catch (ContentRefusedException $e) {
            return $this->refused($e);
        }

        return $this->successResponse(new TeamMemberAdminResource($member->load('translations')), 'api.team.created', 201);
    }

    #[Response(200, type: 'array{success: bool, data: TeamMemberAdminResource}')]
    public function show(TeamMember $member): JsonResponse
    {
        return $this->successResponse(new TeamMemberAdminResource($member->load('translations')));
    }

    /**
     * Change what is the same in every language. Activating needs a complete default-language profile.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: TeamMemberAdminResource}')]
    #[Response(409, description: 'CONTENT_DEFAULT_TRANSLATION_INCOMPLETE: details name the default language.')]
    #[Response(422, description: 'CONTENT_IMAGE_UNAVAILABLE.')]
    public function update(StoreTeamMemberRequest $request, TeamMember $member): JsonResponse
    {
        try {
            $member = $this->team->update($member, $request->sharedChanges(), $this->actor($request));
        } catch (ContentRefusedException $e) {
            return $this->refused($e);
        }

        return $this->successResponse(new TeamMemberAdminResource($member->load('translations')), 'api.team.updated');
    }

    /**
     * Write one language's profile and SEO. The language is any Language Management knows, served or not.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: TeamMemberAdminResource}')]
    #[Response(409, description: 'CONTENT_SLUG_TAKEN or CONTENT_DEFAULT_TRANSLATION_REQUIRED.')]
    #[Response(422, description: 'UNKNOWN_CONTENT_LOCALE, CONTENT_SLUG_INVALID or CONTENT_IMAGE_UNAVAILABLE.')]
    public function writeTranslation(WriteTeamMemberTranslationRequest $request, TeamMember $member, string $locale): JsonResponse
    {
        try {
            $this->team->writeTranslation($member, $locale, $request->content(), $request->seo(), $this->actor($request));
        } catch (ContentRefusedException $e) {
            return $this->refused($e);
        }

        return $this->successResponse(new TeamMemberAdminResource($member->refresh()->load('translations')), 'api.team.translation_saved');
    }

    #[Response(200, type: 'array{success: bool, message: string, data: null}')]
    public function destroy(TeamMember $member): JsonResponse
    {
        $this->team->delete($member);

        return $this->successResponse(null, 'api.team.deleted');
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
