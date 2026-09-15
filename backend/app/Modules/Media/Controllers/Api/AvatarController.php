<?php

declare(strict_types=1);

namespace App\Modules\Media\Controllers\Api;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Exceptions\MediaValidationException;
use App\Modules\Media\Requests\StoreAvatarRequest;
use App\Modules\Media\Services\ProfileAvatars;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

/**
 * The signed-in account's profile picture (ADR 0051 §4).
 *
 * Here rather than beside the rest of the profile because the picture is media, and the
 * module that owns an account may not depend on the one that owns files.
 */
class AvatarController extends BaseApiController
{
    public function __construct(
        protected ProfileAvatars $avatars,
        protected MediaServiceContract $media,
        protected AuditRecorderContract $audit,
    ) {}

    /**
     * Set your profile picture.
     *
     * Replaces the current one. The picture is public and is served once it has been processed; until then `avatar_url` may be null.
     */
    #[Response(201, type: 'array{success: bool, message: string, data: array{media_id: string, status: string, avatar_url: string|null}}')]
    #[Response(422, description: 'MEDIA_REJECTED (the bytes are not an acceptable image) or VALIDATION_ERROR.')]
    public function store(StoreAvatarRequest $request): JsonResponse
    {
        /** @var Model $account */
        $account = $request->user();

        try {
            $media = $this->avatars->replace($account, $request->file('file'));
        } catch (MediaValidationException $e) {
            return $this->errorResponse('MEDIA_REJECTED', $e->translationKey(), ['reason' => $e->reason], 422, $e->translationParameters());
        }

        // The picture is the face an account shows every operator and, for a user, the public.
        // The media id is recorded — it names a file, not a person — and never the file's name.
        $this->audit->succeeded(AuditAction::ACCOUNT_AVATAR_CHANGED, (string) $account->getKey(), [
            'media_id' => $media->id,
        ]);

        return $this->successResponse([
            'media_id' => $media->id,
            'status' => $media->status->value,
            'avatar_url' => $this->media->urlFor($media, $account),
        ], 'Your profile picture was accepted and is being processed.', 201);
    }

    /**
     * Remove your profile picture.
     */
    #[Response(204, description: 'Removed, or there was none.')]
    public function destroy(Request $request): HttpResponse
    {
        /** @var Model $account */
        $account = $request->user();

        // Recorded only when there was a picture to remove: a request that changed nothing is
        // not an event.
        if ($this->avatars->remove($account)) {
            $this->audit->succeeded(AuditAction::ACCOUNT_AVATAR_REMOVED, (string) $account->getKey());
        }

        return response()->noContent();
    }
}
