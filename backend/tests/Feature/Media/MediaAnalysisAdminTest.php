<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
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
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeMediaAnalysisProvider;

/*
 * Media analysis in the Admin (ADR 0054): one consumer of the capability among many. Its
 * reads are private and uncacheable, a manual request and a human review are audited, and
 * nothing it does edits an analysis.
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

function adminAnalysisVideo(): MediaFile
{
    $path = 'private/video/'.Str::lower((string) Str::ulid()).'.mp4';
    Storage::disk('local')->put($path, 'video bytes');

    $media = new MediaFile;
    $media->forceFill([
        'collection' => 'default',
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'entry.mp4',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'type' => MediaType::VIDEO,
        'size_bytes' => 11,
        'checksum' => hash('sha256', $path),
        'visibility' => MediaVisibility::PRIVATE,
        'status' => MediaStatus::READY,
        'scan_status' => ScanStatus::NOT_SCANNED,
    ])->save();

    return $media->refresh();
}

function adminCompletedAnalysis(MediaFile $media): MediaAnalysis
{
    $ticket = app(MediaAnalysisContract::class)->request(MediaAnalysisRequest::for($media->id, ['ai_generated'], 'tests.consumer'));

    (new RunMediaAnalysis((string) $ticket->analysisId))->handle(
        app(MediaAnalyzerContract::class),
        app(MediaAnalysisPolicy::class),
        app(MediaStorageContract::class),
    );

    return MediaAnalysis::query()->findOrFail($ticket->analysisId);
}

test('the capability state is behind media.analysis.view, and is never cacheable', function (): void {
    $this->getJson('/api/v1/admin/media/analysis')->assertStatus(401);

    $this->withToken(tokenWithPermissions(['media.view']))->getJson('/api/v1/admin/media/analysis')->assertStatus(403);
    app('auth')->forgetGuards();

    $viewer = tokenWithPermissions(['media.analysis.view']);

    $this->withToken($viewer)->getJson('/api/v1/admin/media/analysis')
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.reason', 'disabled')
        ->assertJsonPath('data.analyzer', null);

    FakeMediaAnalysisProvider::configure(supportedTypes: ['ai_generated', 'deepfake']);

    $response = $this->withToken($viewer)->getJson('/api/v1/admin/media/analysis')
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.analyzer.provider', 'fake')
        ->assertJsonPath('data.analyzer.supported_types', ['ai_generated', 'deepfake'])
        ->assertJsonPath('data.policy.enabled', true)
        ->assertJsonPath('data.policy.version', 'thresholds:none');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->has('CDN-Cache-Control'))->toBeFalse()
        ->and($response->getContent())->not->toContain('fake-analysis-key');
});

test('an operator requests an analysis as the admin.manual consumer, and the request is audited once', function (): void {
    FakeMediaAnalysisProvider::configure();
    $media = adminAnalysisVideo();
    $token = tokenWithPermissions(['media.analysis.request']);

    $this->withToken($token)->postJson('/api/v1/admin/media/'.$media->id.'/analyses', ['types' => ['ai_generated', 'deepfake']])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.consumer', 'admin.manual');

    $this->withToken($token)->postJson('/api/v1/admin/media/'.$media->id.'/analyses', ['types' => ['ai_generated', 'deepfake']])
        ->assertOk();

    $records = AuditRecord::query()->where('action', AuditAction::MEDIA_ANALYSIS_REQUESTED)->get();

    expect($records)->toHaveCount(1)
        ->and($records->first()->subject)->toBe($media->id)
        ->and(json_encode($records->first()->context))->not->toContain('private/video');
});

test('a switched-off capability is refused, and nothing is audited', function (): void {
    FakeMediaAnalysisProvider::configure(enabled: false);

    $this->withToken(tokenWithPermissions(['media.analysis.request']))
        ->postJson('/api/v1/admin/media/'.adminAnalysisVideo()->id.'/analyses', ['types' => ['ai_generated']])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'MEDIA_ANALYSIS_DISABLED');

    // Configuring the fixture saved a setting, which is audited in its own right; what must
    // not exist is a record of an analysis request that was refused.
    expect(AuditRecord::query()->where('action', AuditAction::MEDIA_ANALYSIS_REQUESTED)->count())->toBe(0);
});

test('an invented type is a validation error, and an unsupported one names itself', function (): void {
    FakeMediaAnalysisProvider::configure(supportedTypes: ['ai_generated']);
    $media = adminAnalysisVideo();
    $token = tokenWithPermissions(['media.analysis.request']);

    $this->withToken($token)->postJson('/api/v1/admin/media/'.$media->id.'/analyses', ['types' => ['is_ai']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $this->withToken($token)->postJson('/api/v1/admin/media/'.$media->id.'/analyses', ['types' => ['deepfake']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'MEDIA_ANALYSIS_UNSUPPORTED_TYPES')
        ->assertJsonPath('error.details.unsupported_types', ['deepfake']);
});

test('requesting needs its own permission, not just the permission to look', function (): void {
    FakeMediaAnalysisProvider::configure();

    $this->withToken(tokenWithPermissions(['media.analysis.view']))
        ->postJson('/api/v1/admin/media/'.adminAnalysisVideo()->id.'/analyses', ['types' => ['ai_generated']])
        ->assertStatus(403);
});

test('a signed-in user outside the Admin cannot read analyses', function (): void {
    Sanctum::actingAs(makeAccount(['email' => 'reader@example.com', 'account_type' => AccountType::USER]), ['user:access']);

    $this->getJson('/api/v1/admin/media/analysis')->assertStatus(403);
});

test('an operator reads a file\'s analyses and a single one', function (): void {
    FakeMediaAnalysisProvider::configure();
    $media = adminAnalysisVideo();
    $analysis = adminCompletedAnalysis($media);
    $token = tokenWithPermissions(['media.analysis.view']);

    $this->withToken($token)->getJson('/api/v1/admin/media/'.$media->id.'/analyses')
        ->assertOk()
        ->assertJsonPath('data.0.id', $analysis->id)
        ->assertJsonPath('data.0.status', 'completed')
        ->assertJsonPath('data.0.scores.ai_generated', 0.5)
        ->assertJsonPath('data.0.type_labels.ai_generated', 'AI generation');

    $this->withToken($token)->getJson('/api/v1/admin/media/analyses/'.$analysis->id)
        ->assertOk()
        ->assertJsonPath('data.provider', 'fake')
        ->assertJsonPath('data.model_version', 'fake-model-1');
});

test('a review is recorded beside the analysis and audited, and the analysis itself is untouched', function (): void {
    FakeMediaAnalysisProvider::configure();
    $analysis = adminCompletedAnalysis(adminAnalysisVideo());
    $before = $analysis->toResult()->toArray();

    $this->withToken(tokenWithPermissions(['media.analysis.review']))
        ->postJson('/api/v1/admin/media/analyses/'.$analysis->id.'/reviews', [
            'decision' => 'confirmed_synthetic',
            'note' => 'The hands change between frames.',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.reviews.0.decision', 'confirmed_synthetic');

    $record = AuditRecord::query()->where('action', AuditAction::MEDIA_ANALYSIS_REVIEWED)->firstOrFail();

    expect($analysis->refresh()->toResult()->toArray())->toBe($before)
        ->and($record->context['decision'])->toBe('confirmed_synthetic')
        ->and($record->context['note_present'])->toBeTrue()
        ->and(json_encode($record->context))->not->toContain('hands change');
});

test('only an assessment can be reviewed', function (): void {
    FakeMediaAnalysisProvider::configure();
    $pending = app(MediaAnalysisContract::class)->request(MediaAnalysisRequest::for(adminAnalysisVideo()->id, ['ai_generated'], 'tests.consumer'));

    $this->withToken(tokenWithPermissions(['media.analysis.review']))
        ->postJson('/api/v1/admin/media/analyses/'.$pending->analysisId.'/reviews', ['decision' => 'undetermined'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'MEDIA_ANALYSIS_NOT_REVIEWABLE');
});

test('an operator can withdraw a pending analysis, and only once', function (): void {
    FakeMediaAnalysisProvider::configure();
    $pending = app(MediaAnalysisContract::class)->request(MediaAnalysisRequest::for(adminAnalysisVideo()->id, ['ai_generated'], 'tests.consumer'));
    $token = tokenWithPermissions(['media.analysis.request']);

    $this->withToken($token)->postJson('/api/v1/admin/media/analyses/'.$pending->analysisId.'/cancel')
        ->assertOk()
        ->assertJsonPath('data.status', MediaAnalysisStatus::CANCELLED->value);

    $this->withToken($token)->postJson('/api/v1/admin/media/analyses/'.$pending->analysisId.'/cancel')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'MEDIA_ANALYSIS_NOT_CANCELLABLE');
});

test('a provider switched on without its credential is refused through the provider editor', function (): void {
    $provider = FakeMediaAnalysisProvider::configure(withCredential: false);
    $provider->forceFill(['is_active' => false])->save();

    $this->withToken(adminToken(roles: ['super_admin']))
        ->putJson('/api/v1/admin/integrations/providers/'.$provider->id, ['is_active' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PROVIDER_CONFIGURATION_INCOMPLETE')
        ->assertJsonPath('error.details.missing', ['api_key']);
});
