<?php

declare(strict_types=1);

namespace App\Modules\Media\Controllers\Api;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Data\MediaUpload;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Exceptions\MediaValidationException;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Requests\StoreMediaRequest;
use App\Modules\Media\Resources\MediaResource;
use App\Modules\Media\Services\MediaAccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Upload and read media as the authenticated caller.
 *
 * Not administrative: media is a platform capability, so any signed-in account may
 * upload and read what it is entitled to. Managing other people's media is a
 * separate, permission-gated surface.
 */
class MediaController extends BaseApiController
{
    public function __construct(
        protected MediaServiceContract $media,
        protected MediaAccessResolver $access,
    ) {}

    /**
     * Upload a file.
     */
    public function store(StoreMediaRequest $request): JsonResponse
    {
        $visibility = MediaVisibility::from(
            (string) ($request->validated('visibility') ?? MediaVisibility::PRIVATE->value)
        );

        try {
            $media = $this->media->store(new MediaUpload(
                file: $request->file('file'),
                visibility: $visibility,
                collection: (string) ($request->validated('collection') ?? 'default'),
                uploadedBy: $request->user()?->id,
            ));
        } catch (MediaValidationException $e) {
            // The reason is machine readable so a client can react to it; the message
            // stays human. Neither discloses anything about storage.
            return $this->errorResponse('MEDIA_REJECTED', $e->translationKey(), ['reason' => $e->reason], 422, $e->translationParameters());
        }

        return $this->successResponse(
            new MediaResource($media, $this->media->urlFor($media, $request->user())),
            'The file was accepted and is being processed.',
            201
        );
    }

    /**
     * Read one file's record.
     *
     * A file the caller may not see is reported exactly as one that does not exist,
     * so this endpoint cannot be used to discover which media ids are real.
     */
    public function show(Request $request, MediaFile $media): JsonResponse
    {
        if (! $this->access->allows($media, $request->user()) && ! $this->ownsUnready($media, $request)) {
            return $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404);
        }

        return $this->successResponse(new MediaResource($media, $this->media->urlFor($media, $request->user())));
    }

    /**
     * An uploader may watch their own file progress through the pipeline before it
     * becomes servable; nobody else can see it at all until it is ready.
     */
    private function ownsUnready(MediaFile $media, Request $request): bool
    {
        return $media->uploaded_by !== null
            && $request->user()?->id === $media->uploaded_by;
    }
}
