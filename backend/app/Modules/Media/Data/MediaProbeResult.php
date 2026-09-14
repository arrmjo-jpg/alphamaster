<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

/**
 * What reading a timed file's metadata established (ADR 0054).
 *
 * Either a reading or the reason there is none, never a guess. A duration that could not be
 * read is null with a stable code beside it, so a limit checked against it can say
 * "unavailable" instead of treating the file as zero seconds long.
 *
 * The codes are stable identifiers. The probe's own output is never kept: its error text
 * names the temporary path it read and says nothing an operator can act on.
 */
final readonly class MediaProbeResult
{
    public const BINARY_UNAVAILABLE = 'binary_unavailable';

    public const FILE_UNAVAILABLE = 'file_unavailable';

    public const FILE_TOO_LARGE = 'file_too_large';

    public const TIMED_OUT = 'timed_out';

    /** The probe refused the file: corrupt, truncated, or not a format it reads. */
    public const PROBE_FAILED = 'probe_failed';

    public const INVALID_OUTPUT = 'invalid_output';

    /** The file opened, and holds neither a video nor an audio stream. */
    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    /** Streams were found, and nothing in the file states how long it is. */
    public const DURATION_UNAVAILABLE = 'duration_unavailable';

    public function __construct(
        public ?int $durationMilliseconds = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $formatName = null,
        public ?string $videoCodec = null,
        public ?string $audioCodec = null,
        public ?string $errorCode = null,
    ) {}

    public static function failed(string $errorCode): self
    {
        return new self(errorCode: $errorCode);
    }

    /**
     * The metadata to merge onto the media record.
     *
     * Width and height are left out when unknown, so processing keeps whatever intake had
     * rather than overwriting it with nothing.
     *
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        $metadata = [
            'duration_available' => $this->durationMilliseconds !== null,
            'duration_ms' => $this->durationMilliseconds,
            'duration_seconds' => $this->durationMilliseconds === null ? null : intdiv($this->durationMilliseconds, 1000),
            'dimensions_available' => $this->width !== null && $this->height !== null,
            'probe' => [
                'format' => $this->formatName,
                'video_codec' => $this->videoCodec,
                'audio_codec' => $this->audioCodec,
            ],
            'probe_error' => $this->errorCode,
        ];

        if ($this->width !== null && $this->height !== null) {
            $metadata['width'] = $this->width;
            $metadata['height'] = $this->height;
        }

        return $metadata;
    }
}
