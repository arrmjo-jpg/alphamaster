<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\MediaAnalysis\MediaAnalysisContract;
use App\Modules\Core\MediaAnalysis\MediaAnalysisOutcome;
use App\Modules\Core\MediaAnalysis\MediaAnalysisRequest;
use App\Modules\Core\MediaAnalysis\MediaAnalysisStatus;
use App\Modules\Core\MediaAnalysis\MediaAnalysisTicket;
use App\Modules\Core\MediaAnalysis\MediaAnalyzerContract;
use App\Modules\Media\Contracts\MediaStorageContract;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Jobs\RunMediaAnalysis;
use App\Modules\Media\Models\MediaAnalysis;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Services\Analysis\MediaAnalysisPolicy;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeMediaAnalysisProvider;
use Tests\Support\SampleMedia;

/*
 * Duration limits for media analysis (ADR 0054): dynamic settings, inclusive bounds checked
 * to the millisecond, applied when a consumer asks for an analysis and never when a file is
 * uploaded, and refusing a file whose duration is unknown rather than sending it anyway.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('local');
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

function timedVideo(?int $milliseconds, MediaType $type = MediaType::VIDEO, string $mime = 'video/mp4'): MediaFile
{
    $path = 'private/video/'.Str::lower((string) Str::ulid()).'.mp4';
    Storage::disk('local')->put($path, 'bytes');

    $media = new MediaFile;
    $media->forceFill([
        'collection' => 'default',
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'clip.mp4',
        'mime_type' => $mime,
        'extension' => 'mp4',
        'type' => $type,
        'size_bytes' => 5,
        'checksum' => hash('sha256', $path),
        'visibility' => MediaVisibility::PRIVATE,
        'status' => MediaStatus::READY,
        'scan_status' => ScanStatus::NOT_SCANNED,
        'duration_seconds' => $milliseconds === null ? null : intdiv($milliseconds, 1000),
        'metadata' => $milliseconds === null ? null : ['duration_available' => true, 'duration_ms' => $milliseconds],
    ])->save();

    return $media->refresh();
}

function durationLimits(?int $minimum, ?int $maximum): void
{
    $settings = app(SettingServiceInterface::class);
    // The maximum first when lowering and the minimum first when raising would be needed
    // through the API; the service writes one at a time, so clear both, then set them.
    $settings->set('media_analysis', 'min_video_duration_seconds', null);
    $settings->set('media_analysis', 'max_video_duration_seconds', null);
    $settings->set('media_analysis', 'max_video_duration_seconds', $maximum);
    $settings->set('media_analysis', 'min_video_duration_seconds', $minimum);
    Cache::flush();
}

function requestTimed(MediaFile $media, string $consumer = 'tests.form_a'): MediaAnalysisTicket
{
    return app(MediaAnalysisContract::class)->request(MediaAnalysisRequest::for($media->id, ['ai_generated'], $consumer));
}

// ── Bounds ──────────────────────────────────────────────────────────────────────

test('with no duration limits set, a video of any readable length may be analysed', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();
    durationLimits(null, null);

    expect(requestTimed(timedVideo(1000))->outcome)->toBe(MediaAnalysisOutcome::QUEUED)
        ->and(requestTimed(timedVideo(7_200_000))->outcome)->toBe(MediaAnalysisOutcome::QUEUED);
});

test('with a minimum of 3:00 and a maximum of 5:00, both bounds are inclusive to the millisecond', function (int $milliseconds, MediaAnalysisOutcome $expected, ?int $limit): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();
    durationLimits(180, 300);

    $ticket = requestTimed(timedVideo($milliseconds));

    expect($ticket->outcome)->toBe($expected)
        ->and($ticket->limitSeconds)->toBe($limit)
        ->and(FakeMediaAnalysisProvider::$calls)->toBe(0);

    if ($expected->accepted()) {
        Queue::assertPushed(RunMediaAnalysis::class, 1);
    } else {
        Queue::assertNotPushed(RunMediaAnalysis::class);
        expect(MediaAnalysis::query()->count())->toBe(0);
    }
})->with([
    '2:59 is too short' => [179_000, MediaAnalysisOutcome::DURATION_TOO_SHORT, 180],
    '2:59.999 is too short' => [179_999, MediaAnalysisOutcome::DURATION_TOO_SHORT, 180],
    'exactly 3:00 is accepted' => [180_000, MediaAnalysisOutcome::QUEUED, null],
    '4:00 is accepted' => [240_000, MediaAnalysisOutcome::QUEUED, null],
    'exactly 5:00 is accepted' => [300_000, MediaAnalysisOutcome::QUEUED, null],
    '5:00.001 is too long' => [300_001, MediaAnalysisOutcome::DURATION_TOO_LONG, 300],
    '5:01 is too long' => [301_000, MediaAnalysisOutcome::DURATION_TOO_LONG, 300],
]);

test('a minimum alone refuses only short media, and a maximum alone only long media', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    durationLimits(60, null);
    expect(requestTimed(timedVideo(59_000))->outcome)->toBe(MediaAnalysisOutcome::DURATION_TOO_SHORT)
        ->and(requestTimed(timedVideo(36_000_000))->outcome)->toBe(MediaAnalysisOutcome::QUEUED);

    durationLimits(null, 60);
    expect(requestTimed(timedVideo(1_000))->outcome)->toBe(MediaAnalysisOutcome::QUEUED)
        ->and(requestTimed(timedVideo(61_000))->outcome)->toBe(MediaAnalysisOutcome::DURATION_TOO_LONG);
});

test('the limits are settings, not numbers in code: changing them changes the answer for the same video', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();
    $video = timedVideo(150_000);

    durationLimits(180, 300);
    expect(requestTimed($video)->outcome)->toBe(MediaAnalysisOutcome::DURATION_TOO_SHORT);

    durationLimits(60, 120);
    expect(requestTimed($video)->outcome)->toBe(MediaAnalysisOutcome::DURATION_TOO_LONG);

    durationLimits(120, 180);
    expect(requestTimed($video)->outcome)->toBe(MediaAnalysisOutcome::QUEUED);
});

test('the analyzer\'s own maximum applies when it is stricter than the operator\'s', function (): void {
    FakeMediaAnalysisProvider::configure();
    FakeMediaAnalysisProvider::$maxDurationSeconds = 60;
    Queue::fake();
    durationLimits(null, 300);

    $ticket = requestTimed(timedVideo(120_000));

    expect($ticket->outcome)->toBe(MediaAnalysisOutcome::DURATION_TOO_LONG)
        ->and($ticket->limitSeconds)->toBe(60);
});

test('a video whose duration is unknown is refused, and never sent on the assumption it fits', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    durationLimits(null, null);
    $unknown = requestTimed(timedVideo(null));

    $unreadable = timedVideo(null);
    $unreadable->forceFill(['metadata' => ['duration_available' => false, 'probe_error' => 'probe_failed']])->save();

    expect($unknown->outcome)->toBe(MediaAnalysisOutcome::DURATION_UNAVAILABLE)
        ->and(requestTimed($unreadable)->outcome)->toBe(MediaAnalysisOutcome::DURATION_UNAVAILABLE)
        ->and(MediaAnalysis::query()->count())->toBe(0)
        ->and(FakeMediaAnalysisProvider::$calls)->toBe(0);

    Queue::assertNothingPushed();
});

test('media that does not play for a length of time has no duration to limit', function (): void {
    FakeMediaAnalysisProvider::configure();
    FakeMediaAnalysisProvider::$acceptedMimeTypes = ['image/'];
    Queue::fake();
    durationLimits(180, 300);

    expect(requestTimed(timedVideo(null, MediaType::IMAGE, 'image/png'))->outcome)->toBe(MediaAnalysisOutcome::QUEUED);
});

test('limits tightened while an analysis waits cancel it before the file is sent', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();
    durationLimits(null, 300);

    $ticket = requestTimed(timedVideo(240_000));
    durationLimits(null, 200);

    (new RunMediaAnalysis((string) $ticket->analysisId))->handle(
        app(MediaAnalyzerContract::class),
        app(MediaAnalysisPolicy::class),
        app(MediaStorageContract::class),
    );

    $analysis = MediaAnalysis::query()->findOrFail($ticket->analysisId);

    expect($analysis->status)->toBe(MediaAnalysisStatus::CANCELLED)
        ->and($analysis->error_code)->toBe('DURATION_TOO_LONG')
        ->and(FakeMediaAnalysisProvider::$calls)->toBe(0);
});

// ── The critical isolation: upload is not analysis ──────────────────────────────

test('an eight-minute upload succeeds, and an analysis of it under a five-minute maximum is refused without calling the provider', function (): void {
    config(['queue.default' => 'sync']);
    FakeMediaAnalysisProvider::configure();
    durationLimits(null, 300);

    $token = adminToken();

    // Media upload: the real endpoint, the real pipeline, a real eight-minute video.
    $upload = $this->withToken($token)->post('/api/v1/media', [
        'file' => SampleMedia::upload(SampleMedia::video(480), 'eight-minutes.mp4', 'video/mp4'),
    ], ['Accept' => 'application/json']);

    $upload->assertCreated();
    $media = MediaFile::query()->findOrFail($upload->json('data.id'));

    expect($media->status)->toBe(MediaStatus::READY)
        ->and($media->durationMilliseconds())->toBeGreaterThanOrEqual(479_000)->toBeLessThanOrEqual(481_000)
        ->and(MediaAnalysis::query()->count())->toBe(0);

    // AI analysis request: refused, recorded nowhere, sent nowhere.
    Queue::fake();
    $ticket = requestTimed($media, 'tests.form_a');

    expect($ticket->outcome)->toBe(MediaAnalysisOutcome::DURATION_TOO_LONG)
        ->and($ticket->limitSeconds)->toBe(300)
        ->and(MediaAnalysis::query()->count())->toBe(0)
        ->and(FakeMediaAnalysisProvider::$calls)->toBe(0)
        ->and(FakeMediaAnalysisProvider::$lastInput)->toBeNull();

    Queue::assertNotPushed(RunMediaAnalysis::class);

    // And the file is still there, exactly as uploaded.
    expect($media->refresh()->status)->toBe(MediaStatus::READY);
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/media/'.$media->id)->assertOk();
});

// ── The settings ────────────────────────────────────────────────────────────────

test('the duration settings are published as seconds, optional, with no default', function (): void {
    $definitions = collect($this->withToken(adminToken(roles: ['administrator']))
        ->getJson('/api/v1/admin/settings/definitions')
        ->assertOk()
        ->json('data.media_analysis'))->keyBy('name');

    foreach (['min_video_duration_seconds', 'max_video_duration_seconds'] as $key) {
        expect($definitions[$key]['unit'])->toBe('seconds')
            ->and($definitions[$key]['nullable'])->toBeTrue()
            ->and($definitions[$key]['default'])->toBeNull()
            ->and($definitions[$key]['type'])->toBe('integer');
    }

    expect($definitions)->not->toHaveKey('max_duration_seconds');
});

test('an operator cannot save a negative duration, or a minimum longer than the maximum', function (): void {
    $token = adminToken(roles: ['administrator']);
    $write = fn (array $settings) => $this->withToken($token)
        ->withHeader('If-Match', '"'.settingsVersion('media_analysis').'"')
        ->putJson('/api/v1/admin/settings/media_analysis', ['settings' => $settings]);

    $write(['min_video_duration_seconds' => -1])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SETTING_VALUE_REJECTED');

    $refused = $write(['min_video_duration_seconds' => 400, 'max_video_duration_seconds' => 300])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SETTING_VALUE_REJECTED')
        ->assertJsonPath('error.details.setting', 'media_analysis.min_video_duration_seconds');

    expect($refused->json('error.details.messages.0'))->toContain('cannot be longer')
        ->and(setting('media_analysis.min_video_duration_seconds'))->toBeNull()
        ->and(setting('media_analysis.max_video_duration_seconds'))->toBeNull();

    $write(['min_video_duration_seconds' => 300, 'max_video_duration_seconds' => 300])->assertOk();
    Cache::flush();

    // Against what is already saved, too: lowering the maximum below the stored minimum.
    $write(['max_video_duration_seconds' => 200])
        ->assertStatus(422)
        ->assertJsonPath('error.details.setting', 'media_analysis.min_video_duration_seconds');

    $write(['min_video_duration_seconds' => null, 'max_video_duration_seconds' => 0])->assertOk();
    Cache::flush();

    expect(setting('media_analysis.min_video_duration_seconds'))->toBeNull()
        ->and(setting('media_analysis.max_video_duration_seconds'))->toBe(0);
});

test('a unit is declared only on a number, and only from the units a client knows', function (): void {
    expect(fn () => new SettingDefinition(group: 'demo', key: 'label', type: SettingType::STRING, unit: 'seconds'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SettingDefinition(group: 'demo', key: 'wait', type: SettingType::INTEGER, unit: 'fortnights'))
        ->toThrow(InvalidArgumentException::class)
        ->and((new SettingDefinition(group: 'demo', key: 'wait', type: SettingType::INTEGER, unit: 'seconds'))->unit)
        ->toBe('seconds');
});
