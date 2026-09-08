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
use Dedoc\Scramble\Attributes\Response as ResponseShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Serve the bytes.
     *
     * Returns the stored file itself, with the media type recorded for it. The
     * response carries a strong `ETag` derived from the file's checksum, so a
     * conditional request with `If-None-Match` is answered `304`. It is cacheable
     * privately and indefinitely: the bytes behind a media id never change.
     *
     * A file the caller may not see is reported exactly as one that does not exist.
     */
    #[ResponseShape(
        status: 200,
        description: 'The file, with the media type recorded for it.',
        mediaType: 'application/octet-stream',
        type: 'string',
        format: 'binary',
    )]
    #[ResponseShape(status: 304, description: 'The copy already held is still current.')]
    public function file(Request $request, MediaFile $media): StreamedResponse|JsonResponse|Response
    {
        // The platform is API-only (ADR 0001) and the proxy in front of it reads no
        // files, so a stored object has no other way to reach a browser:
        // `Storage::url()` composes a path against a public disk and a symlink this
        // deployment does not have, and nothing is listening on it. Streaming here is
        // slower than a web server handing over a file, and is the only arrangement in
        // which the access decision and the bytes cannot come apart — which is what
        // private media needs regardless.
        //
        // Refused exactly as `show` refuses, and for the same reason: reporting a
        // forbidden file as a missing one keeps this from being a way to discover
        // which media ids are real.
        if (! $this->access->allows($media, $request->user())) {
            return $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404);
        }

        // The stored object is immutable — the path carries a generated ULID and a new
        // upload never overwrites an old one — so the checksum is a strong validator
        // and a conditional request can be answered without touching the disk.
        $etag = '"'.$media->checksum.'"';

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response()->noContent(304, ['ETag' => $etag]);
        }

        $stream = $this->media->readStream($media);

        if ($stream === null) {
            // Recorded but no longer on disk. Not a 500: nothing is broken here, the
            // object is gone, and that is what a caller needs to be told.
            return $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404);
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $media->mime_type,
            'Content-Length' => (string) $media->size_bytes,
            // Named for display and quoted, and only ever as an attachment name — the
            // client's filename never became part of a path and does not become one
            // here either.
            'Content-Disposition' => 'inline; filename="'.addslashes($media->original_filename).'"',
            'ETag' => $etag,
            // Private in the cache sense whatever the visibility: this route is behind
            // authentication, so a shared cache must not keep a copy on behalf of one
            // caller and hand it to another. Immutable, because the bytes at this id
            // never change.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
