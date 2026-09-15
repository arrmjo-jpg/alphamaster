<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\MediaAnalysis\AnalyzerOutcome;
use App\Modules\Core\MediaAnalysis\Events\MediaAnalysisFinished;
use App\Modules\Core\MediaAnalysis\MediaAnalysisAvailability;
use App\Modules\Core\MediaAnalysis\MediaAnalysisClassification;
use App\Modules\Core\MediaAnalysis\MediaAnalysisContract;
use App\Modules\Core\MediaAnalysis\MediaAnalysisInput;
use App\Modules\Core\MediaAnalysis\MediaAnalysisOutcome;
use App\Modules\Core\MediaAnalysis\MediaAnalysisRequest;
use App\Modules\Core\MediaAnalysis\MediaAnalysisStatus;
use App\Modules\Core\MediaAnalysis\MediaAnalysisTicket;
use App\Modules\Core\MediaAnalysis\MediaAnalyzerContract;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Contracts\MediaStorageContract;
use App\Modules\Media\Data\MediaUpload;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Jobs\RunMediaAnalysis;
use App\Modules\Media\Jobs\SweepMediaAnalyses;
use App\Modules\Media\Models\MediaAnalysis;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Services\Analysis\MediaAnalysisPolicy;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeMediaAnalysisProvider;

/*
 * Media analysis — AI video detection — as a capability any consumer calls explicitly
 * (ADR 0054): nothing is analysed unless something asks, an operator's switch makes it
 * available and nothing more, and a result is a versioned assessment rather than a verdict.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('local');
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

function analysisVideo(array $overrides = []): MediaFile
{
    $path = 'private/video/'.Str::lower((string) Str::ulid()).'.mp4';
    Storage::disk('local')->put($path, 'not really a video');

    $media = new MediaFile;
    $media->forceFill(array_merge([
        'collection' => 'default',
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'clip.mp4',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'type' => MediaType::VIDEO,
        'size_bytes' => 18,
        'checksum' => hash('sha256', $path),
        'visibility' => MediaVisibility::PRIVATE,
        'status' => MediaStatus::READY,
        'scan_status' => ScanStatus::NOT_SCANNED,
        // What processing records for a readable four-minute video.
        'duration_seconds' => 240,
        'metadata' => ['duration_available' => true, 'duration_ms' => 240000],
    ], $overrides))->save();

    return $media->refresh();
}

function analysisSetting(string $key, mixed $value): void
{
    app(SettingServiceInterface::class)->set('media_analysis', $key, $value);
    Cache::flush();
}

/**
 * @param  list<string>  $types
 */
function requestAnalysis(MediaFile $media, array $types = ['ai_generated', 'deepfake'], string $consumer = 'tests.form_a', bool $reanalyze = false): MediaAnalysisTicket
{
    return app(MediaAnalysisContract::class)->request(MediaAnalysisRequest::for($media->id, $types, $consumer, $reanalyze));
}

function runAnalysisJob(string $analysisId): MediaAnalysis
{
    (new RunMediaAnalysis($analysisId))->handle(
        app(MediaAnalyzerContract::class),
        app(MediaAnalysisPolicy::class),
        app(MediaStorageContract::class),
    );

    return MediaAnalysis::query()->findOrFail($analysisId);
}

function analysisPng(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'alpha_analysis_');
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAFUlEQVR42mNk'
        .'+M+ACzDhVQGVUEsBAP7bA/1B9AAAAABJRU5ErkJggg=='
    ));

    return new UploadedFile($path, 'photo.png', 'image/png', null, true);
}

// ── The consumer decides; nothing else does ─────────────────────────────────────

test('uploading media never starts an analysis, even with the capability on and configured', function (): void {
    FakeMediaAnalysisProvider::configure();
    config(['queue.default' => 'sync']);

    $stored = app(MediaServiceContract::class)->store(new MediaUpload(file: analysisPng()));
    analysisVideo();

    expect($stored->refresh()->status)->toBe(MediaStatus::READY)
        ->and(MediaAnalysis::query()->count())->toBe(0)
        ->and(FakeMediaAnalysisProvider::$calls)->toBe(0);
});

test('one form may ask for analysis while another does not, and only the media asked about is analysed', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $formA = analysisVideo();
    $formB = analysisVideo();

    // Form A calls the capability; Form B does not call anything.
    $ticket = requestAnalysis($formA, consumer: 'tests.form_a');

    expect($ticket->outcome)->toBe(MediaAnalysisOutcome::QUEUED)
        ->and(MediaAnalysis::query()->where('media_file_id', $formA->id)->value('consumer'))->toBe('tests.form_a')
        ->and(MediaAnalysis::query()->where('media_file_id', $formB->id)->count())->toBe(0);

    Queue::assertPushed(RunMediaAnalysis::class, 1);
});

test('switched off, a request is refused as disabled and nothing is recorded or sent', function (): void {
    FakeMediaAnalysisProvider::configure(enabled: false);
    Queue::fake();

    $ticket = requestAnalysis(analysisVideo());

    expect($ticket->outcome)->toBe(MediaAnalysisOutcome::DISABLED)
        ->and($ticket->analysisId)->toBeNull()
        ->and(app(MediaAnalysisContract::class)->availability()->reason)->toBe(MediaAnalysisAvailability::DISABLED)
        ->and(MediaAnalysis::query()->count())->toBe(0)
        ->and(FakeMediaAnalysisProvider::$calls)->toBe(0);

    Queue::assertNothingPushed();
});

test('switched on with no analyzer configured, a request is refused as not configured', function (): void {
    analysisSetting('enabled', true);

    expect(requestAnalysis(analysisVideo())->outcome)->toBe(MediaAnalysisOutcome::NOT_CONFIGURED)
        ->and(app(MediaAnalysisContract::class)->availability()->reason)->toBe(MediaAnalysisAvailability::NOT_CONFIGURED);
});

test('a provider missing its credential is unavailable, not half-working', function (): void {
    FakeMediaAnalysisProvider::configure(withCredential: false);

    expect(requestAnalysis(analysisVideo())->outcome)->toBe(MediaAnalysisOutcome::NOT_CONFIGURED)
        ->and(app(MediaAnalysisContract::class)->availability()->available)->toBeFalse();
});

test('availability lists the types the configured analyzer supports', function (): void {
    FakeMediaAnalysisProvider::configure(supportedTypes: ['ai_generated', 'deepfake']);

    $availability = app(MediaAnalysisContract::class)->availability();

    expect($availability->available)->toBeTrue()
        ->and($availability->supports('deepfake'))->toBeTrue()
        ->and($availability->supports('synthetic_audio'))->toBeFalse();
});

test('media that does not exist or is not ready is refused', function (): void {
    FakeMediaAnalysisProvider::configure();

    expect(app(MediaAnalysisContract::class)->request(MediaAnalysisRequest::for((string) Str::ulid(), ['ai_generated'], 'tests.form_a'))->outcome)
        ->toBe(MediaAnalysisOutcome::MEDIA_NOT_FOUND)
        ->and(requestAnalysis(analysisVideo(['status' => MediaStatus::PROCESSING]))->outcome)
        ->toBe(MediaAnalysisOutcome::MEDIA_NOT_READY)
        ->and(requestAnalysis(analysisVideo(['scan_status' => ScanStatus::INFECTED]))->outcome)
        ->toBe(MediaAnalysisOutcome::MEDIA_NOT_READY);
});

test('a file the analyzer does not accept is unsupported media', function (): void {
    FakeMediaAnalysisProvider::configure();

    $image = analysisVideo(['mime_type' => 'image/png', 'type' => MediaType::IMAGE]);

    expect(requestAnalysis($image)->outcome)->toBe(MediaAnalysisOutcome::UNSUPPORTED_MEDIA)
        ->and(MediaAnalysis::query()->count())->toBe(0);
});

test('types the analyzer does not support are reported, never scored as zero', function (): void {
    FakeMediaAnalysisProvider::configure(supportedTypes: ['ai_generated']);
    Queue::fake();

    $refused = requestAnalysis(analysisVideo(), ['deepfake']);

    expect($refused->outcome)->toBe(MediaAnalysisOutcome::UNSUPPORTED_TYPES)
        ->and($refused->unsupportedTypes)->toBe(['deepfake']);

    $partial = requestAnalysis(analysisVideo(), ['ai_generated', 'deepfake']);

    expect($partial->outcome)->toBe(MediaAnalysisOutcome::QUEUED)
        ->and($partial->unsupportedTypes)->toBe(['deepfake']);

    $result = runAnalysisJob((string) $partial->analysisId)->toResult();

    expect($result->scores)->toBe(['ai_generated' => 0.5])
        ->and($result->scores)->not->toHaveKey('deepfake')
        ->and($result->score('deepfake'))->toBeNull()
        ->and($result->unsupportedTypes)->toContain('deepfake');
});

test('a consumer cannot invent a type, and names itself as an identifier', function (): void {
    $id = (string) Str::ulid();

    expect(fn () => MediaAnalysisRequest::for($id, ['is_ai'], 'tests.form_a'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MediaAnalysisRequest::for($id, [], 'tests.form_a'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MediaAnalysisRequest::for($id, ['ai_generated'], 'App\\News\\Form'))->toThrow(InvalidArgumentException::class);
});

// ── Limits ──────────────────────────────────────────────────────────────────────

test('the operator\'s size limit refuses a request before anything is recorded', function (): void {
    FakeMediaAnalysisProvider::configure();

    analysisSetting('max_file_size_mb', 1);
    $large = requestAnalysis(analysisVideo(['size_bytes' => 2 * 1024 * 1024]));

    expect($large->outcome)->toBe(MediaAnalysisOutcome::LIMIT_EXCEEDED)
        ->and($large->detail)->toBe('max_file_size')
        ->and(MediaAnalysis::query()->count())->toBe(0)
        ->and(FakeMediaAnalysisProvider::$calls)->toBe(0);
});

test('the analyzer\'s own limit applies when it is stricter', function (): void {
    FakeMediaAnalysisProvider::configure();
    FakeMediaAnalysisProvider::$maxBytes = 10;

    expect(requestAnalysis(analysisVideo())->detail)->toBe('max_file_size');
});

test('the daily limit stops the next request once it is reached', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();
    analysisSetting('daily_limit', 1);

    expect(requestAnalysis(analysisVideo())->outcome)->toBe(MediaAnalysisOutcome::QUEUED)
        ->and(requestAnalysis(analysisVideo())->detail)->toBe('daily_limit');
});

// ── Async, dedupe ───────────────────────────────────────────────────────────────

test('a request is recorded as pending and queued on the media-analysis queue', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $ticket = requestAnalysis(analysisVideo());
    $result = app(MediaAnalysisContract::class)->find((string) $ticket->analysisId);

    expect($result?->status)->toBe(MediaAnalysisStatus::PENDING)
        ->and(FakeMediaAnalysisProvider::$calls)->toBe(0);

    Queue::assertPushedOn('media-analysis', RunMediaAnalysis::class);
});

test('asking again for the same analysis of the same content reuses it instead of paying twice', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $media = analysisVideo();
    $first = requestAnalysis($media, ['deepfake', 'ai_generated']);
    $second = requestAnalysis($media, ['ai_generated', 'deepfake'], consumer: 'tests.form_c');

    expect($second->outcome)->toBe(MediaAnalysisOutcome::REUSED)
        ->and($second->analysisId)->toBe($first->analysisId)
        ->and(MediaAnalysis::query()->count())->toBe(1);

    Queue::assertPushed(RunMediaAnalysis::class, 1);
});

// ── Results ─────────────────────────────────────────────────────────────────────

test('a completed analysis records scores, confidence and what produced it, and says so to its consumer', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();
    Event::fake([MediaAnalysisFinished::class]);

    $ticket = requestAnalysis(analysisVideo());
    $statusDuringAnalysis = null;

    FakeMediaAnalysisProvider::answer(function (MediaAnalysisInput $input) use (&$statusDuringAnalysis): AnalyzerOutcome {
        $statusDuringAnalysis = MediaAnalysis::query()->find($input->analysisId)?->status;

        return AnalyzerOutcome::assessed(['ai_generated' => 0.91, 'deepfake' => 0.12], confidence: 0.8, modelVersion: 'fake-model-1', reference: 'vendor-ref');
    });

    $result = runAnalysisJob((string) $ticket->analysisId)->toResult();

    expect($statusDuringAnalysis)->toBe(MediaAnalysisStatus::PROCESSING)
        ->and($result->status)->toBe(MediaAnalysisStatus::COMPLETED)
        ->and($result->scores)->toBe(['ai_generated' => 0.91, 'deepfake' => 0.12])
        ->and($result->confidence)->toBe(0.8)
        ->and($result->provider)->toBe('fake')
        ->and($result->analyzer)->toBe('fake-detector')
        ->and($result->modelVersion)->toBe('fake-model-1')
        ->and($result->policyVersion)->toBe('thresholds:none')
        ->and($result->classification)->toBeNull()
        ->and($result->inputFingerprint)->toHaveLength(64)
        ->and($result->attempts)->toBe(1)
        ->and($result->completedAt)->not->toBeNull()
        ->and(IntegrationUsageLog::query()->where('capability', 'media_analysis')->where('status', 'success')->value('units'))->toBe(1);

    Event::assertDispatched(MediaAnalysisFinished::class, static fn (MediaAnalysisFinished $event): bool => $event->consumer === 'tests.form_a'
        && $event->status === MediaAnalysisStatus::COMPLETED
        && $event->analysisId === $ticket->analysisId);
});

test('no result carries a boolean verdict', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $array = runAnalysisJob((string) requestAnalysis(analysisVideo())->analysisId)->toResult()->toArray();

    expect(array_keys($array))->not->toContain('is_ai')
        ->and(array_keys($array))->not->toContain('is_ai_generated')
        ->and(array_keys($array))->not->toContain('ai_generated');

    foreach ($array['scores'] as $score) {
        expect($score)->toBeFloat();
    }
});

test('thresholds give an advisory classification, recorded with the policy version it came from', function (float $score, MediaAnalysisClassification $expected): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();
    analysisSetting('likely_synthetic_threshold', 0.8);
    analysisSetting('likely_authentic_threshold', 0.2);

    $ticket = requestAnalysis(analysisVideo(), ['ai_generated']);
    FakeMediaAnalysisProvider::answer(static fn (): AnalyzerOutcome => AnalyzerOutcome::assessed(['ai_generated' => $score]));

    $result = runAnalysisJob((string) $ticket->analysisId)->toResult();

    expect($result->classification)->toBe($expected)
        ->and($result->policyVersion)->toStartWith('thresholds:')
        ->and($result->policyVersion)->not->toBe('thresholds:none');
})->with([
    'high' => [0.93, MediaAnalysisClassification::LIKELY_SYNTHETIC],
    'low' => [0.05, MediaAnalysisClassification::LIKELY_AUTHENTIC],
    'between' => [0.5, MediaAnalysisClassification::INCONCLUSIVE],
]);

test('an inconclusive analysis says so, and never reads as authentic', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $ticket = requestAnalysis(analysisVideo());
    FakeMediaAnalysisProvider::answer(static fn (): AnalyzerOutcome => AnalyzerOutcome::inconclusive(signals: [['kind' => 'low_resolution']]));

    $result = runAnalysisJob((string) $ticket->analysisId)->toResult();

    expect($result->status)->toBe(MediaAnalysisStatus::INCONCLUSIVE)
        ->and($result->classification)->toBe(MediaAnalysisClassification::INCONCLUSIVE)
        ->and($result->signals)->toBe([['kind' => 'low_resolution']]);
});

test('an analyzer that could assess none of the requested types marks the analysis unsupported', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $ticket = requestAnalysis(analysisVideo());
    FakeMediaAnalysisProvider::answer(static fn (): AnalyzerOutcome => AnalyzerOutcome::assessed([], unsupportedTypes: ['ai_generated', 'deepfake']));

    $result = runAnalysisJob((string) $ticket->analysisId)->toResult();

    expect($result->status)->toBe(MediaAnalysisStatus::UNSUPPORTED)
        ->and($result->classification)->toBeNull()
        ->and($result->scores)->toBe([]);
});

test('a failure is recorded as a failure, never as an authentic reading', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $ticket = requestAnalysis(analysisVideo());
    FakeMediaAnalysisProvider::answer(static fn (): AnalyzerOutcome => AnalyzerOutcome::failure('VENDOR_REFUSED', 'The file was refused.', retryable: false));

    $result = runAnalysisJob((string) $ticket->analysisId)->toResult();

    expect($result->status)->toBe(MediaAnalysisStatus::FAILED)
        ->and($result->classification)->toBeNull()
        ->and($result->scores)->toBe([])
        ->and($result->errorCode)->toBe('VENDOR_REFUSED');
});

test('a retryable failure waits for the vendor, retries, and gives up after the last attempt', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $ticket = requestAnalysis(analysisVideo());
    FakeMediaAnalysisProvider::answer(static fn (): AnalyzerOutcome => AnalyzerOutcome::failure('RATE_LIMITED', 'Slow down.', retryable: true, retryAfterSeconds: 45));

    $row = runAnalysisJob((string) $ticket->analysisId);

    expect($row->status)->toBe(MediaAnalysisStatus::PENDING)
        ->and($row->attempts)->toBe(1)
        ->and($row->error_code)->toBe('RATE_LIMITED')
        ->and(abs($row->available_at->diffInSeconds(now()->addSeconds(45))))->toBeLessThan(5);

    Queue::assertPushed(RunMediaAnalysis::class, static fn (RunMediaAnalysis $job): bool => $job->delay !== null);

    $row->forceFill(['attempts' => RunMediaAnalysis::MAX_ATTEMPTS - 1, 'available_at' => null])->save();

    expect(runAnalysisJob($row->id)->status)->toBe(MediaAnalysisStatus::FAILED);
});

test('an analyzer that throws is treated as a transient failure, not a crash', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $ticket = requestAnalysis(analysisVideo());
    FakeMediaAnalysisProvider::answer(static function (): AnalyzerOutcome {
        throw new RuntimeException('connection reset');
    });

    $row = runAnalysisJob((string) $ticket->analysisId);

    expect($row->status)->toBe(MediaAnalysisStatus::PENDING)
        ->and($row->error_code)->toBe('DRIVER_ERROR');
});

test('scores outside 0 to 1, or for types nobody asked about, fail the analysis', function (array $scores): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $ticket = requestAnalysis(analysisVideo(), ['ai_generated']);
    FakeMediaAnalysisProvider::answer(static fn (): AnalyzerOutcome => AnalyzerOutcome::assessed($scores));

    $row = runAnalysisJob((string) $ticket->analysisId);

    expect($row->status)->toBe(MediaAnalysisStatus::FAILED)
        ->and($row->error_code)->toBe('ANALYZER_INVALID_RESULT');
})->with([
    'too high' => [['ai_generated' => 1.5]],
    'not requested' => [['synthetic_audio' => 0.4]],
]);

test('the analyzer reaches the file only through its input, when it asks', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $ticket = requestAnalysis(analysisVideo());
    $bytes = null;

    FakeMediaAnalysisProvider::answer(function (MediaAnalysisInput $input) use (&$bytes): AnalyzerOutcome {
        $stream = $input->openStream();
        $bytes = $stream === null ? null : stream_get_contents($stream);

        return AnalyzerOutcome::assessed(['ai_generated' => 0.3]);
    });

    runAnalysisJob((string) $ticket->analysisId);

    expect($bytes)->toBe('not really a video')
        ->and(get_object_vars(FakeMediaAnalysisProvider::$lastInput))->not->toHaveKey('path');
});

// ── Cancellation ─────────────────────────────────────────────────────────────────

test('a pending analysis can be withdrawn and then never runs; a finished one cannot', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $service = app(MediaAnalysisContract::class);
    $pending = requestAnalysis(analysisVideo());

    expect($service->cancel((string) $pending->analysisId))->toBeTrue()
        ->and($service->find((string) $pending->analysisId)?->status)->toBe(MediaAnalysisStatus::CANCELLED);

    runAnalysisJob((string) $pending->analysisId);

    expect(FakeMediaAnalysisProvider::$calls)->toBe(0);

    $finished = requestAnalysis(analysisVideo());
    runAnalysisJob((string) $finished->analysisId);

    expect($service->cancel((string) $finished->analysisId))->toBeFalse();
});

test('switching the capability off cancels what has not started, without calling the analyzer', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $ticket = requestAnalysis(analysisVideo());
    analysisSetting('enabled', false);

    $row = runAnalysisJob((string) $ticket->analysisId);

    expect($row->status)->toBe(MediaAnalysisStatus::CANCELLED)
        ->and($row->error_code)->toBe('ANALYSIS_DISABLED')
        ->and(FakeMediaAnalysisProvider::$calls)->toBe(0);
});

test('removing the media cancels its pending analysis', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $media = analysisVideo();
    $ticket = requestAnalysis($media);
    $media->delete();

    $row = runAnalysisJob((string) $ticket->analysisId);

    expect($row->status)->toBe(MediaAnalysisStatus::CANCELLED)
        ->and($row->error_code)->toBe('MEDIA_UNAVAILABLE');
});

// ── Versioning ───────────────────────────────────────────────────────────────────

test('a re-analysis never rewrites the earlier result, and supersedes it once it has its own', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $service = app(MediaAnalysisContract::class);
    $media = analysisVideo();

    FakeMediaAnalysisProvider::answer(static fn (): AnalyzerOutcome => AnalyzerOutcome::assessed(['ai_generated' => 0.2, 'deepfake' => 0.1]));
    $first = runAnalysisJob((string) requestAnalysis($media)->analysisId);
    $before = $first->toResult()->toArray();

    $again = requestAnalysis($media, reanalyze: true);

    expect($again->outcome)->toBe(MediaAnalysisOutcome::QUEUED)
        ->and($again->analysisId)->not->toBe($first->id)
        ->and($service->latest($media->id)?->id)->toBe($again->analysisId);

    FakeMediaAnalysisProvider::answer(static fn (): AnalyzerOutcome => AnalyzerOutcome::assessed(['ai_generated' => 0.7, 'deepfake' => 0.6]));
    runAnalysisJob((string) $again->analysisId);

    $after = $first->refresh()->toResult()->toArray();

    expect($after['superseded_by'])->toBe($again->analysisId)
        ->and(array_diff_key($after, ['superseded_by' => true]))->toBe(array_diff_key($before, ['superseded_by' => true]))
        ->and($service->latest($media->id)?->scores)->toBe(['ai_generated' => 0.7, 'deepfake' => 0.6])
        ->and($service->history($media->id))->toHaveCount(2)
        ->and($service->find($again->analysisId)?->reanalysisOf)->toBe($first->id);
});

test('a failed re-analysis leaves the earlier result current', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $media = analysisVideo();
    $first = runAnalysisJob((string) requestAnalysis($media)->analysisId);

    $again = requestAnalysis($media, reanalyze: true);
    FakeMediaAnalysisProvider::answer(static fn (): AnalyzerOutcome => AnalyzerOutcome::failure('VENDOR_REFUSED', 'No.', retryable: false));
    runAnalysisJob((string) $again->analysisId);

    expect($first->refresh()->superseded_by)->toBeNull()
        ->and(app(MediaAnalysisContract::class)->latest($media->id, 'tests.form_a')?->id)->toBe((string) $again->analysisId);
});

test('a new model version is a new analysis, not a reuse of the old one', function (): void {
    $provider = FakeMediaAnalysisProvider::configure(model: 'fake-model-1');
    Queue::fake();

    $media = analysisVideo();
    $first = runAnalysisJob((string) requestAnalysis($media)->analysisId);

    $provider->forceFill(['settings' => ['model' => 'fake-model-2']])->save();
    Cache::flush();

    $second = requestAnalysis($media);

    expect($second->outcome)->toBe(MediaAnalysisOutcome::QUEUED)
        ->and($second->analysisId)->not->toBe($first->id)
        ->and(MediaAnalysis::query()->find($second->analysisId)?->model_version)->toBe('fake-model-2');
});

// ── Recovery ─────────────────────────────────────────────────────────────────────

test('the sweep recovers an analysis a dead worker left running and one whose dispatch was lost', function (): void {
    FakeMediaAnalysisProvider::configure();
    Queue::fake();

    $stuck = MediaAnalysis::query()->findOrFail(requestAnalysis(analysisVideo())->analysisId);
    $stuck->forceFill(['status' => MediaAnalysisStatus::PROCESSING, 'started_at' => now()->subHours(2)])->save();

    $lost = MediaAnalysis::query()->findOrFail(requestAnalysis(analysisVideo())->analysisId);
    MediaAnalysis::query()->whereKey($lost->id)->update(['updated_at' => now()->subHour()]);

    Queue::fake();
    (new SweepMediaAnalyses)->handle(app(MediaAnalysisPolicy::class));

    expect($stuck->refresh()->status)->toBe(MediaAnalysisStatus::PENDING);

    Queue::assertPushed(RunMediaAnalysis::class, static fn (RunMediaAnalysis $job): bool => $job->analysisId === $stuck->id);
    Queue::assertPushed(RunMediaAnalysis::class, static fn (RunMediaAnalysis $job): bool => $job->analysisId === $lost->id);
});
