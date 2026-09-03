<?php

declare(strict_types=1);

namespace App\Modules\Media\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Models\MediaFile;
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
            fn (MediaFile $file): array => $this->present($file)
        ));
    }

    /**
     * Inspect one record.
     */
    public function show(MediaFile $media): JsonResponse
    {
        return $this->successResponse($this->present($media->loadMissing('uploader')));
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

    /**
     * Administrative view of a record.
     *
     * Includes the uploader and the failure reason, which an ordinary caller has no
     * need for, but still not the disk or the storage path.
     *
     * @return array<string, mixed>
     */
    private function present(MediaFile $media): array
    {
        return [
            'id' => $media->id,
            'collection' => $media->collection,
            'original_filename' => $media->original_filename,
            'mime_type' => $media->mime_type,
            'type' => $media->type->value,
            'size_bytes' => $media->size_bytes,
            'checksum' => $media->checksum,
            'visibility' => $media->visibility->value,
            'status' => $media->status->value,
            'scan_status' => $media->scan_status->value,
            'failure_reason' => $media->failure_reason,
            'attachable_type' => $media->attachable_type,
            'attachable_id' => $media->attachable_id,
            'uploaded_by' => $media->uploader?->email,
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }
}
