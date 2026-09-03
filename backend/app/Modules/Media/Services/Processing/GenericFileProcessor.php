<?php

declare(strict_types=1);

namespace App\Modules\Media\Services\Processing;

use App\Modules\Media\Contracts\MediaProcessorContract;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaFile;
use Illuminate\Support\Facades\Storage;

/**
 * The metadata this environment can actually derive.
 *
 * Image dimensions come from EXIF where the file carries them; there is no gd or
 * imagick here, so nothing is decoded and no thumbnail is produced. Video duration
 * and dimensions need ffprobe, which is likewise absent, so a video yields only what
 * intake already established.
 *
 * This processor is deliberately honest about that: it reports what it could not
 * determine rather than writing zeros, so a null width means unknown rather than a
 * file that is genuinely zero pixels wide.
 */
class GenericFileProcessor implements MediaProcessorContract
{
    public function __construct(private readonly MediaType $type) {}

    public function handles(): MediaType
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function process(MediaFile $media): array
    {
        $metadata = [
            'processor' => 'generic',
            'derived_at' => now()->toIso8601String(),
        ];

        if ($this->type !== MediaType::IMAGE) {
            // Video and audio need ffprobe; documents need a parser. Neither exists
            // here, so the capability is recorded as unavailable rather than faked.
            $metadata['dimensions_available'] = false;

            return $metadata;
        }

        return array_merge($metadata, $this->imageDimensions($media));
    }

    /**
     * Dimensions from EXIF, when the file carries them.
     *
     * @return array<string, mixed>
     */
    private function imageDimensions(MediaFile $media): array
    {
        $contents = $this->contents($media);

        if ($contents === null) {
            return ['dimensions_available' => false];
        }

        // getimagesizefromstring reads the header only and does not decode the image,
        // so it needs no image extension and cannot be made to execute anything.
        $size = @getimagesizefromstring($contents);

        if ($size === false) {
            return ['dimensions_available' => false];
        }

        return [
            'width' => (int) $size[0],
            'height' => (int) $size[1],
            'dimensions_available' => true,
        ];
    }

    private function contents(MediaFile $media): ?string
    {
        try {
            $contents = Storage::disk($media->disk)->get($media->path);
        } catch (\Throwable) {
            return null;
        }

        return is_string($contents) ? $contents : null;
    }
}
