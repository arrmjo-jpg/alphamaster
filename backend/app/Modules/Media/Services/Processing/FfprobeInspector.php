<?php

declare(strict_types=1);

namespace App\Modules\Media\Services\Processing;

use App\Modules\Media\Contracts\MediaStorageContract;
use App\Modules\Media\Data\MediaProbeResult;
use App\Modules\Media\Models\MediaFile;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Reads a stored video's or audio file's duration with ffprobe (ADR 0054).
 *
 * The one place the platform runs a program against a file someone uploaded, so the ways
 * that can go wrong are closed here rather than hoped against:
 *
 * * **No shell.** The command is an argument list handed straight to the process, so no
 *   character in a path or a filename is ever interpreted. Nothing a request supplied
 *   reaches the command at all: the path is a temporary file this class named.
 * * **Files only.** `-protocol_whitelist file` and a `file:` input stop a crafted container
 *   from making ffprobe open a network address or another file on the host.
 * * **Bounded.** A size ceiling before anything is copied, `-probesize` and
 *   `-analyzeduration` on what ffprobe may read, a wall-clock timeout, a capped output, and
 *   a lower scheduling priority.
 * * **Cleaned up.** Storage may be remote, so the file is copied to a temporary path, and
 *   that path is removed on every exit, including a timeout and an exception.
 *
 * It never throws. A file it cannot read yields a result with a stable code, which the
 * pipeline records beside the media; the upload is not failed over a duration.
 */
class FfprobeInspector
{
    /** Temporary copies are named with this, so a test can prove none are left behind. */
    public const TEMP_PREFIX = 'alphamaster-probe-';

    private const MAX_OUTPUT_BYTES = 1048576;

    public function __construct(private readonly MediaStorageContract $storage) {}

    public function inspect(MediaFile $media): MediaProbeResult
    {
        $maxBytes = max(1, (int) config('media.probe.max_bytes', 104857600));

        if ($media->size_bytes > $maxBytes) {
            return MediaProbeResult::failed(MediaProbeResult::FILE_TOO_LARGE);
        }

        $binary = $this->binary();

        if ($binary === null) {
            return MediaProbeResult::failed(MediaProbeResult::BINARY_UNAVAILABLE);
        }

        $local = null;

        try {
            $local = $this->copyToTemporaryFile($media, $maxBytes);

            if ($local === null) {
                return MediaProbeResult::failed(MediaProbeResult::FILE_UNAVAILABLE);
            }

            return $this->probe($binary, $local);
        } catch (Throwable) {
            return MediaProbeResult::failed(MediaProbeResult::PROBE_FAILED);
        } finally {
            if ($local !== null && is_file($local)) {
                @unlink($local);
            }
        }
    }

    /**
     * The executable, resolved without a shell, or null when there is none.
     */
    public function binary(): ?string
    {
        $configured = (string) config('media.probe.binary', 'ffprobe');

        if ($configured === '') {
            return null;
        }

        if (str_contains($configured, DIRECTORY_SEPARATOR)) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find($configured);
    }

    private function probe(string $binary, string $path): MediaProbeResult
    {
        $command = [
            ...$this->priority(),
            $binary,
            '-v', 'error',
            '-hide_banner',
            '-protocol_whitelist', 'file',
            '-probesize', (string) max(32, (int) config('media.probe.probe_size_bytes', 10000000)),
            '-analyzeduration', (string) max(0, (int) config('media.probe.analyze_duration_microseconds', 10000000)),
            '-print_format', 'json',
            '-show_entries', 'format=duration,format_name:stream=codec_type,codec_name,width,height,duration',
            'file:'.$path,
        ];

        try {
            $result = Process::timeout(max(1, (int) config('media.probe.timeout_seconds', 30)))->run($command);
        } catch (ProcessTimedOutException) {
            return MediaProbeResult::failed(MediaProbeResult::TIMED_OUT);
        }

        if (! $result->successful()) {
            return MediaProbeResult::failed(MediaProbeResult::PROBE_FAILED);
        }

        $output = $result->output();

        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            return MediaProbeResult::failed(MediaProbeResult::INVALID_OUTPUT);
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return MediaProbeResult::failed(MediaProbeResult::INVALID_OUTPUT);
        }

        return $this->read($decoded);
    }

    /**
     * @param  array<mixed>  $decoded
     */
    private function read(array $decoded): MediaProbeResult
    {
        $format = is_array($decoded['format'] ?? null) ? $decoded['format'] : [];
        $streams = is_array($decoded['streams'] ?? null) ? array_filter($decoded['streams'], 'is_array') : [];

        $video = $this->firstStream($streams, 'video');
        $audio = $this->firstStream($streams, 'audio');

        if ($video === null && $audio === null) {
            return new MediaProbeResult(
                formatName: $this->string($format['format_name'] ?? null),
                errorCode: MediaProbeResult::UNSUPPORTED_FORMAT,
            );
        }

        // The container's own duration first; a stream's when the container states none.
        $candidates = [$format['duration'] ?? null];

        foreach ($streams as $stream) {
            $candidates[] = $stream['duration'] ?? null;
        }

        $milliseconds = null;

        foreach ($candidates as $candidate) {
            $milliseconds = $this->milliseconds($candidate);

            if ($milliseconds !== null) {
                break;
            }
        }

        return new MediaProbeResult(
            durationMilliseconds: $milliseconds,
            width: $video === null ? null : $this->dimension($video['width'] ?? null),
            height: $video === null ? null : $this->dimension($video['height'] ?? null),
            formatName: $this->string($format['format_name'] ?? null),
            videoCodec: $video === null ? null : $this->string($video['codec_name'] ?? null),
            audioCodec: $audio === null ? null : $this->string($audio['codec_name'] ?? null),
            errorCode: $milliseconds === null ? MediaProbeResult::DURATION_UNAVAILABLE : null,
        );
    }

    /**
     * @param  array<mixed>  $streams
     * @return array<mixed>|null
     */
    private function firstStream(array $streams, string $type): ?array
    {
        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === $type) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * ffprobe reports seconds as a decimal string, and "N/A" where it has none.
     */
    private function milliseconds(mixed $seconds): ?int
    {
        if (! is_numeric($seconds)) {
            return null;
        }

        $value = (float) $seconds;

        // Longer than a week is not a video anyone uploaded within the size limit; it is a
        // header that lies, and is treated as stating nothing.
        if (! is_finite($value) || $value <= 0 || $value > 604800) {
            return null;
        }

        return (int) round($value * 1000);
    }

    private function dimension(mixed $value): ?int
    {
        return is_int($value) && $value > 0 && $value <= 65535 ? $value : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 100) : null;
    }

    /**
     * @return list<string>
     */
    private function priority(): array
    {
        $niceness = config('media.probe.niceness');

        if (! is_numeric($niceness)) {
            return [];
        }

        $nice = (new ExecutableFinder)->find('nice');

        return $nice === null ? [] : [$nice, '-n', (string) max(0, min(19, (int) $niceness))];
    }

    /**
     * Copies the stored object to a temporary file, or null when it is gone or larger than
     * the ceiling. A partial copy is removed before returning.
     */
    private function copyToTemporaryFile(MediaFile $media, int $maxBytes): ?string
    {
        $source = $this->storage->readStream($media->path, $media->disk);

        if (! is_resource($source)) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), self::TEMP_PREFIX);

        if ($path === false) {
            fclose($source);

            return null;
        }

        $target = fopen($path, 'wb');

        if ($target === false) {
            fclose($source);
            @unlink($path);

            return null;
        }

        try {
            $copied = stream_copy_to_stream($source, $target, $maxBytes + 1);
        } finally {
            fclose($source);
            fclose($target);
        }

        if ($copied === false || $copied > $maxBytes) {
            @unlink($path);

            return null;
        }

        return $path;
    }
}
