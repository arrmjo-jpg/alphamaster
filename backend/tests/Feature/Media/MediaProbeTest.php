<?php

declare(strict_types=1);

use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Data\MediaProbeResult;
use App\Modules\Media\Data\MediaUpload;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Jobs\ProbeMediaDuration;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Services\Processing\FfprobeInspector;
use App\Modules\Media\Services\ProcessorRegistry;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\SampleMedia;

/*
 * Reading a video's duration with ffprobe (ADR 0054): the duration media analysis limits are
 * checked against, read once during intake, safely, and never at the cost of the upload.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('local');
    $this->seed(SettingSeeder::class);
});

function probedFile(string $source, string $mime = 'video/mp4', MediaType $type = MediaType::VIDEO, ?string $path = null): MediaFile
{
    $path ??= 'private/video/'.Str::lower((string) Str::ulid()).'.mp4';
    Storage::disk('local')->put($path, (string) file_get_contents($source));

    $media = new MediaFile;
    $media->forceFill([
        'collection' => 'default',
        'disk' => 'local',
        'path' => $path,
        'original_filename' => basename($path),
        'mime_type' => $mime,
        'extension' => pathinfo($path, PATHINFO_EXTENSION),
        'type' => $type,
        'size_bytes' => (int) filesize($source),
        'checksum' => hash_file('sha256', $source),
        'visibility' => MediaVisibility::PRIVATE,
        'status' => MediaStatus::READY,
        'scan_status' => ScanStatus::NOT_SCANNED,
    ])->save();

    return $media->refresh();
}

/**
 * @return list<string>
 */
function leftoverProbeCopies(): array
{
    return glob(sys_get_temp_dir().'/'.FfprobeInspector::TEMP_PREFIX.'*') ?: [];
}

test('ffprobe is installed in the image the media workers run', function (): void {
    expect(app(FfprobeInspector::class)->binary())->not->toBeNull();
});

test('a valid video yields its duration, dimensions and codecs', function (): void {
    $result = app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::video(3)));

    expect($result->errorCode)->toBeNull()
        ->and($result->durationMilliseconds)->toBeGreaterThanOrEqual(2900)->toBeLessThanOrEqual(3300)
        ->and($result->width)->toBe(16)
        ->and($result->height)->toBe(16)
        ->and($result->videoCodec)->toBe('mpeg4')
        ->and($result->audioCodec)->toBe('aac');
});

test('an uploaded video carries its duration from the moment it is ready', function (): void {
    config(['queue.default' => 'sync']);

    $media = app(MediaServiceContract::class)->store(new MediaUpload(
        file: SampleMedia::upload(SampleMedia::video(4), 'clip.mp4', 'video/mp4'),
    ))->refresh();

    expect($media->status)->toBe(MediaStatus::READY)
        ->and($media->duration_seconds)->toBeIn([3, 4])
        ->and($media->durationMilliseconds())->toBeGreaterThanOrEqual(3900)->toBeLessThanOrEqual(4300)
        ->and($media->metadata['duration_available'])->toBeTrue()
        ->and($media->metadata['probe_error'])->toBeNull()
        ->and($media->width)->toBe(16);
});

test('a corrupted video is still stored and ready, with its duration recorded as unreadable', function (): void {
    config(['queue.default' => 'sync']);

    expect(app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::truncatedVideo()))->errorCode)
        ->toBe(MediaProbeResult::PROBE_FAILED);

    $media = app(MediaServiceContract::class)->store(new MediaUpload(
        file: SampleMedia::upload(SampleMedia::video(4), 'clip.mp4', 'video/mp4'),
    ));

    // The same pipeline, against bytes that no longer form a video.
    $damaged = SampleMedia::truncatedVideo();
    Storage::disk('local')->put($media->path, (string) file_get_contents($damaged));
    $media->forceFill(['size_bytes' => filesize($damaged), 'metadata' => null, 'duration_seconds' => null])->save();
    (new ProbeMediaDuration($media->id))->handle(app(ProcessorRegistry::class));

    $media->refresh();

    expect($media->status)->toBe(MediaStatus::READY)
        ->and($media->durationMilliseconds())->toBeNull()
        ->and($media->metadata['duration_available'])->toBeFalse()
        ->and($media->metadata['probe_error'])->toBe(MediaProbeResult::PROBE_FAILED);
});

test('a file with neither video nor audio is an unsupported format, not a zero-length video', function (): void {
    $result = app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::subtitles(), 'text/plain', MediaType::VIDEO));

    expect($result->errorCode)->toBe(MediaProbeResult::UNSUPPORTED_FORMAT)
        ->and($result->durationMilliseconds)->toBeNull();
});

test('a probe that cannot say how long a file is records that, instead of a guess', function (string $body, string $expected): void {
    config(['media.probe.binary' => SampleMedia::fakeProbe($body)]);

    $result = app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::video(1)));

    expect($result->errorCode)->toBe($expected)
        ->and($result->durationMilliseconds)->toBeNull();
})->with([
    'streams but no duration' => ['echo \'{"format":{"format_name":"mov","duration":"N/A"},"streams":[{"codec_type":"video","codec_name":"h264"}]}\'', MediaProbeResult::DURATION_UNAVAILABLE],
    'a negative duration' => ['echo \'{"format":{"duration":"-4"},"streams":[{"codec_type":"audio"}]}\'', MediaProbeResult::DURATION_UNAVAILABLE],
    'output that is not JSON' => ['echo "Segmentation fault"', MediaProbeResult::INVALID_OUTPUT],
    'a non-zero exit' => ['echo "{}"; exit 1', MediaProbeResult::PROBE_FAILED],
]);

test('without ffprobe the duration is unavailable, and nothing throws', function (): void {
    config(['media.probe.binary' => '/nonexistent/ffprobe']);

    expect(app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::video(1)))->errorCode)
        ->toBe(MediaProbeResult::BINARY_UNAVAILABLE);
});

test('a probe that hangs is stopped at its timeout', function (): void {
    config(['media.probe.binary' => SampleMedia::fakeProbe('sleep 10'), 'media.probe.timeout_seconds' => 1]);

    $started = microtime(true);
    $result = app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::video(1)));

    expect($result->errorCode)->toBe(MediaProbeResult::TIMED_OUT)
        ->and(microtime(true) - $started)->toBeLessThan(6);
});

test('a file over the probe ceiling is never copied out of storage', function (): void {
    config(['media.probe.max_bytes' => 10]);
    $before = leftoverProbeCopies();

    expect(app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::video(1)))->errorCode)
        ->toBe(MediaProbeResult::FILE_TOO_LARGE)
        ->and(leftoverProbeCopies())->toBe($before);
});

test('no temporary copy survives a probe, whether it succeeds, fails or times out', function (): void {
    $before = leftoverProbeCopies();

    app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::video(1)));
    app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::truncatedVideo()));

    config(['media.probe.binary' => SampleMedia::fakeProbe('echo nonsense')]);
    app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::video(1)));

    config(['media.probe.binary' => SampleMedia::fakeProbe('sleep 10'), 'media.probe.timeout_seconds' => 1]);
    app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::video(1)));

    expect(leftoverProbeCopies())->toBe($before);
});

test('ffprobe is run without a shell, restricted to local files, on a path the platform chose', function (): void {
    $arguments = sys_get_temp_dir().'/alphamaster-sample-args-'.Str::random(8).'.txt';
    $marker = sys_get_temp_dir().'/alphamaster-sample-injected-'.Str::random(8);
    config(['media.probe.binary' => SampleMedia::fakeProbe('printf "%s\n" "$@" > '.$arguments."\necho '{}'")]);

    // A stored name full of shell syntax. Were anything interpolated into a shell, the marker
    // would exist afterwards.
    $hostile = 'private/video/$(touch '.$marker.');`touch '.$marker.'`.mp4';

    app(FfprobeInspector::class)->inspect(probedFile(SampleMedia::video(1), path: $hostile));

    $passed = array_values(array_filter(explode("\n", (string) file_get_contents($arguments))));

    expect(file_exists($marker))->toBeFalse()
        ->and($passed)->toContain('-protocol_whitelist')
        ->and($passed[array_search('-protocol_whitelist', $passed, true) + 1])->toBe('file')
        ->and(end($passed))->toStartWith('file:'.sys_get_temp_dir().'/'.FfprobeInspector::TEMP_PREFIX)
        ->and(implode(' ', $passed))->not->toContain('touch');

    @unlink($arguments);
});

test('files taken in before durations were read can be probed once, and are not probed again', function (): void {
    Queue::fake();

    $old = probedFile(SampleMedia::video(2));
    $alreadyRead = probedFile(SampleMedia::video(2));
    $alreadyRead->forceFill(['metadata' => ['duration_available' => false, 'probe_error' => 'timed_out']])->save();
    $image = probedFile(SampleMedia::video(1), 'image/png', MediaType::IMAGE);

    $this->artisan('media:probe-durations')->assertSuccessful();

    Queue::assertPushed(ProbeMediaDuration::class, 1);
    Queue::assertPushed(ProbeMediaDuration::class, fn (ProbeMediaDuration $job): bool => $job->mediaId === $old->id);

    (new ProbeMediaDuration($old->id))->handle(app(ProcessorRegistry::class));
    $derivedAt = $old->refresh()->metadata['derived_at'];

    expect($old->durationMilliseconds())->toBeGreaterThanOrEqual(1900)->toBeLessThanOrEqual(2300)
        ->and($old->status)->toBe(MediaStatus::READY);

    (new ProbeMediaDuration($old->id))->handle(app(ProcessorRegistry::class));
    (new ProbeMediaDuration($image->id))->handle(app(ProcessorRegistry::class));

    expect($old->refresh()->metadata['derived_at'])->toBe($derivedAt)
        ->and($image->refresh()->metadata)->toBeNull();
});
