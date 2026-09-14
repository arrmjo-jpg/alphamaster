<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\MediaAnalysis\AnalyzerOutcome;
use App\Modules\Core\MediaAnalysis\MediaAnalysisContract;
use App\Modules\Core\MediaAnalysis\MediaAnalysisRequest;
use App\Modules\Core\MediaAnalysis\MediaAnalysisStatus;
use App\Modules\Core\MediaAnalysis\MediaAnalyzerContract;
use App\Modules\Core\Models\AuditRecord;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeMediaAnalysisProvider;

/*
 * What media analysis promises beyond producing a result (ADR 0054): one analysis is sent
 * once, its results never reach a shared cache, nothing secret reaches the audit trail, each
 * action needs its own permission, and an expected refusal is a stable 422 rather than an
 * error.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Queue::fake();
    Storage::fake('local');
    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    AuditRecord::query()->getQuery()->delete();
});

function guaranteedVideo(?int $milliseconds = 90_000): MediaFile
{
    $path = 'private/video/'.Str::lower((string) Str::ulid()).'.mp4';
    Storage::disk('local')->put($path, 'bytes');

    $media = new MediaFile;
    $media->forceFill([
        'collection' => 'default',
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'clip.mp4',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'type' => MediaType::VIDEO,
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

function runGuaranteedJob(string $analysisId): void
{
    (new RunMediaAnalysis($analysisId))->handle(
        app(MediaAnalyzerContract::class),
        app(MediaAnalysisPolicy::class),
        app(MediaStorageContract::class),
    );
}

test('an analysis is sent to the analyzer once, however many times its job runs', function (): void {
    FakeMediaAnalysisProvider::configure();

    $ticket = app(MediaAnalysisContract::class)->request(MediaAnalysisRequest::for(guaranteedVideo()->id, ['ai_generated'], 'tests.form_a'));

    runGuaranteedJob((string) $ticket->analysisId);
    runGuaranteedJob((string) $ticket->analysisId);

    $analysis = MediaAnalysis::query()->findOrFail($ticket->analysisId);

    expect(FakeMediaAnalysisProvider::$calls)->toBe(1)
        ->and($analysis->attempts)->toBe(1)
        ->and($analysis->status)->toBe(MediaAnalysisStatus::COMPLETED);

    // A second worker arriving while the first is still analysing claims nothing.
    $running = app(MediaAnalysisContract::class)->request(MediaAnalysisRequest::for(guaranteedVideo()->id, ['ai_generated'], 'tests.form_a'));
    MediaAnalysis::query()->whereKey($running->analysisId)->update(['status' => MediaAnalysisStatus::PROCESSING->value, 'attempts' => 1]);

    runGuaranteedJob((string) $running->analysisId);

    expect(FakeMediaAnalysisProvider::$calls)->toBe(1)
        ->and(MediaAnalysis::query()->findOrFail($running->analysisId)->attempts)->toBe(1);
});

test('no analysis response can be stored by a browser, a proxy or the CDN', function (): void {
    FakeMediaAnalysisProvider::configure();
    $media = guaranteedVideo();
    $ticket = app(MediaAnalysisContract::class)->request(MediaAnalysisRequest::for($media->id, ['ai_generated'], 'tests.form_a'));
    runGuaranteedJob((string) $ticket->analysisId);

    $token = tokenWithPermissions(['media.analysis.view']);

    foreach (['/api/v1/admin/media/analysis', '/api/v1/admin/media/'.$media->id.'/analyses', '/api/v1/admin/media/analyses/'.$ticket->analysisId] as $uri) {
        $response = $this->withToken($token)->getJson($uri)->assertOk();

        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Cache-Control'))->not->toContain('public')
            ->and($response->headers->has('CDN-Cache-Control'))->toBeFalse()
            ->and($response->headers->has('Surrogate-Control'))->toBeFalse();
    }
});

test('neither credentials, signed URLs, storage paths nor a reviewer\'s note reach the audit trail', function (): void {
    FakeMediaAnalysisProvider::configure();
    FakeMediaAnalysisProvider::answer(function ($input) {
        // A vendor driver asking for the file the way a real one would.
        $input->openStream();

        return AnalyzerOutcome::assessed(scores: ['ai_generated' => 0.7], reference: 'vendor-job-123');
    });

    $media = guaranteedVideo();
    $token = tokenWithPermissions(['media.analysis.request', 'media.analysis.review', 'media.analysis.view']);

    $analysisId = $this->withToken($token)
        ->postJson('/api/v1/admin/media/'.$media->id.'/analyses', ['types' => ['ai_generated']])
        ->assertStatus(202)
        ->json('data.id');

    runGuaranteedJob($analysisId);

    $this->withToken($token)
        ->postJson('/api/v1/admin/media/analyses/'.$analysisId.'/reviews', ['decision' => 'confirmed_synthetic', 'note' => 'Private reviewer reasoning'])
        ->assertCreated();

    $trail = json_encode(AuditRecord::query()->get()->map->toArray()->all());

    expect(AuditRecord::query()->where('action', AuditAction::MEDIA_ANALYSIS_REQUESTED)->count())->toBe(1)
        ->and(AuditRecord::query()->where('action', AuditAction::MEDIA_ANALYSIS_REVIEWED)->count())->toBe(1)
        ->and($trail)->not->toContain('fake-analysis-key')
        ->and($trail)->not->toContain($media->path)
        ->and($trail)->not->toContain('Private reviewer reasoning')
        ->and($trail)->not->toContain('vendor-job-123')
        ->and($trail)->not->toContain('signature')
        ->and($trail)->not->toContain('http');
});

test('an analysis a module requests is not audited: its row is the record', function (): void {
    FakeMediaAnalysisProvider::configure();

    app(MediaAnalysisContract::class)->request(MediaAnalysisRequest::for(guaranteedVideo()->id, ['ai_generated'], 'tests.form_a'));

    expect(MediaAnalysis::query()->count())->toBe(1)
        ->and(AuditRecord::query()->where('action', AuditAction::MEDIA_ANALYSIS_REQUESTED)->count())->toBe(0);
});

test('viewing, requesting and reviewing each need their own permission', function (): void {
    FakeMediaAnalysisProvider::configure();
    $media = guaranteedVideo();
    $ticket = app(MediaAnalysisContract::class)->request(MediaAnalysisRequest::for($media->id, ['ai_generated'], 'tests.form_a'));
    runGuaranteedJob((string) $ticket->analysisId);

    $requester = tokenWithPermissions(['media.analysis.request']);
    $this->withToken($requester)->getJson('/api/v1/admin/media/'.$media->id.'/analyses')->assertStatus(403);
    $this->withToken($requester)->postJson('/api/v1/admin/media/analyses/'.$ticket->analysisId.'/reviews', ['decision' => 'undetermined'])->assertStatus(403);
    app('auth')->forgetGuards();

    $viewer = tokenWithPermissions(['media.analysis.view']);
    $this->withToken($viewer)->getJson('/api/v1/admin/media/'.$media->id.'/analyses')->assertOk();
    $this->withToken($viewer)->postJson('/api/v1/admin/media/'.$media->id.'/analyses', ['types' => ['ai_generated']])->assertStatus(403);
    $this->withToken($viewer)->postJson('/api/v1/admin/media/analyses/'.$ticket->analysisId.'/reviews', ['decision' => 'undetermined'])->assertStatus(403);
    app('auth')->forgetGuards();

    $reviewer = tokenWithPermissions(['media.analysis.review']);
    $this->withToken($reviewer)->postJson('/api/v1/admin/media/analyses/'.$ticket->analysisId.'/reviews', ['decision' => 'undetermined'])->assertCreated();
    $this->withToken($reviewer)->postJson('/api/v1/admin/media/'.$media->id.'/analyses', ['types' => ['ai_generated']])->assertStatus(403);
});

test('a duration refusal through the API is a stable 422 naming the duration and the limit, and is not audited', function (): void {
    FakeMediaAnalysisProvider::configure();
    $settings = app(SettingServiceInterface::class);
    $token = tokenWithPermissions(['media.analysis.request', 'media.analysis.view']);

    $settings->set('media_analysis', 'max_video_duration_seconds', 60);
    Cache::flush();

    $this->withToken($token)->postJson('/api/v1/admin/media/'.guaranteedVideo(120_000)->id.'/analyses', ['types' => ['ai_generated']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'MEDIA_ANALYSIS_DURATION_TOO_LONG')
        ->assertJsonPath('error.details.duration_ms', 120000)
        ->assertJsonPath('error.details.limit_seconds', 60);

    $settings->set('media_analysis', 'max_video_duration_seconds', null);
    $settings->set('media_analysis', 'min_video_duration_seconds', 180);
    Cache::flush();

    $this->withToken($token)->postJson('/api/v1/admin/media/'.guaranteedVideo(120_000)->id.'/analyses', ['types' => ['ai_generated']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'MEDIA_ANALYSIS_DURATION_TOO_SHORT')
        ->assertJsonPath('error.details.limit_seconds', 180);

    $this->withToken($token)->postJson('/api/v1/admin/media/'.guaranteedVideo(null)->id.'/analyses', ['types' => ['ai_generated']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'MEDIA_ANALYSIS_DURATION_UNAVAILABLE');

    $this->withToken($token)->getJson('/api/v1/admin/media/analysis')
        ->assertOk()
        ->assertJsonPath('data.policy.min_video_duration_seconds', 180)
        ->assertJsonPath('data.policy.max_video_duration_seconds', null);

    expect(MediaAnalysis::query()->count())->toBe(0)
        ->and(FakeMediaAnalysisProvider::$calls)->toBe(0)
        ->and(AuditRecord::query()->where('action', AuditAction::MEDIA_ANALYSIS_REQUESTED)->count())->toBe(0);
});
