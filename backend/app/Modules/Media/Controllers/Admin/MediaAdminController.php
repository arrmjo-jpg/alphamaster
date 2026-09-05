<?php

declare(strict_types=1);

namespace App\Modules\Media\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Resources\MediaAdminResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administrative oversight of media.
 *
 * Deliberately narrow: listing, inspecting and removing. Administrators moderate what
 * exists; they are not a second upload path, and giving them one would put business
 * rules about who may attach what into an admin controller.
 */
class MediaAdminController extends BaseApiController
{
    public function __construct(
        protected MediaServiceContract $media
    ) {}

    /**
     * List media, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MediaFile::query()->with('uploader')->latest();

        if (is_string($status = $request->query('status')) && $status !== '') {
            $query->where('status', $status);
        }

        if (is_string($type = $request->query('type')) && $type !== '') {
            $query->where('type', $type);
        }

        return $this->paginatedResponse($query->paginate(25)->through(
            fn (MediaFile $file): MediaAdminResource => new MediaAdminResource($file)
        ));
    }

    /**
     * Inspect one record.
     */
    public function show(MediaFile $media): JsonResponse
    {
        return $this->successResponse(new MediaAdminResource($media->loadMissing('uploader')));
    }

    /**
     * Soft delete. The bytes are purged later by the retention job, so a mistaken
     * removal here is recoverable.
     */
    public function destroy(MediaFile $media): JsonResponse
    {
        $this->media->delete($media);

        return $this->successResponse(
            null,
            'The media was removed. Stored files are purged by the retention job.'
        );
    }
}
