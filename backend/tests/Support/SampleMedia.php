<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Real media files, made with ffmpeg when a test needs one (ADR 0054).
 *
 * Generated rather than committed: a few kilobytes of black frames and silence are made in
 * well under a second, and a test that says "an eight-minute video" can then be exactly that
 * rather than a binary fixture nobody can read.
 */
final class SampleMedia
{
    /**
     * A video of the given length, with a video and an audio stream.
     */
    public static function video(int $seconds): string
    {
        $path = self::temporaryPath('mp4');

        $result = Process::timeout(120)->run([
            'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'color=c=black:s=16x16:r=2',
            '-f', 'lavfi', '-i', 'anullsrc=r=8000:cl=mono',
            '-t', (string) $seconds,
            '-c:v', 'mpeg4', '-c:a', 'aac',
            $path,
        ]);

        if (! $result->successful() || ! is_file($path)) {
            throw new RuntimeException('ffmpeg could not make a sample video: '.$result->errorOutput());
        }

        return $path;
    }

    /**
     * A damaged video: a genuine MP4 header, followed by bytes that are no longer the file.
     *
     * Deterministically unreadable. Merely cutting a small sample short is not — ffmpeg may
     * have written everything ffprobe needs into the part that survives.
     */
    public static function truncatedVideo(): string
    {
        $source = self::video(4);
        $bytes = (string) file_get_contents($source);
        @unlink($source);

        // The `ftyp` box states its own length in its first four bytes.
        $headerLength = unpack('N', substr($bytes, 0, 4))[1] ?? 32;

        $path = self::temporaryPath('mp4');
        file_put_contents($path, substr($bytes, 0, (int) $headerLength).random_bytes(8192));

        return $path;
    }

    /**
     * A file ffprobe opens and that holds neither video nor audio: a subtitle track.
     */
    public static function subtitles(): string
    {
        $path = self::temporaryPath('srt');
        file_put_contents($path, "1\n00:00:00,000 --> 00:00:02,000\nNot a video.\n\n2\n00:00:02,500 --> 00:00:04,000\nStill not.\n");

        return $path;
    }

    /**
     * An executable standing in for ffprobe, so a test can decide what it answers.
     */
    public static function fakeProbe(string $body): string
    {
        $path = self::temporaryPath('sh');
        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0755);

        return $path;
    }

    public static function upload(string $path, string $name, string $mime): UploadedFile
    {
        return new UploadedFile($path, $name, $mime, null, true);
    }

    private static function temporaryPath(string $extension): string
    {
        return sys_get_temp_dir().'/alphamaster-sample-'.Str::lower(Str::random(16)).'.'.$extension;
    }
}
