<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Audit\AuditArchivist;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('archives');
    config(['audit.archive.disk' => 'archives', 'audit.archive.path' => 'audit-archives']);

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    AuditRecord::query()->getQuery()->delete();
});

/**
 * Write a record directly, aged as requested.
 *
 * Through the query builder because the model refuses updates and generates its own
 * timestamp — and the whole subject here is records older than the platform has been
 * running in this test.
 */
function agedRecord(string $action, int $daysOld, string $subject = 'thing'): string
{
    $id = (string) Str::ulid();

    DB::table('audit_records')->insert([
        'id' => $id,
        'actor_id' => null,
        'action' => $action,
        'subject' => $subject,
        'outcome' => 'succeeded',
        'context' => json_encode(['note' => 'aged fixture']),
        'correlation_id' => null,
        'created_at' => Carbon::now()->subDays($daysOld),
    ]);

    return $id;
}

/** Run an archival through the API. */
function archiveTrail(mixed $test, ?string $token = null): mixed
{
    $token ??= adminToken(roles: ['super_admin']);

    resetClient($test);

    return $test->withToken($token)->postJson('/api/v1/admin/audit/archive');
}

// ── Eligibility ──────────────────────────────────────────────────────────────

test('only records past the retention window are eligible', function (): void {
    agedRecord('setting.updated', 400, 'old');
    agedRecord('setting.updated', 10, 'recent');

    $eligible = app(AuditArchivist::class)->eligible(365)->get();

    expect($eligible)->toHaveCount(1)
        ->and($eligible->first()?->subject)->toBe('old');
});

test('a record describing an archival is never itself eligible', function (): void {
    agedRecord(AuditAction::AUDIT_ARCHIVED, 4000, 'archives');
    agedRecord('setting.updated', 4000, 'ordinary');

    $eligible = app(AuditArchivist::class)->eligible(365)->get();

    // Without this exclusion a patient sequence of operations erases the evidence that
    // any of them happened, one window at a time, and the trail ends up
    // complete-looking and false (ADR 0037).
    expect($eligible)->toHaveCount(1)
        ->and($eligible->first()?->subject)->toBe('ordinary');
});

test('the retention setting describes eligibility and never triggers a run', function (): void {
    agedRecord('setting.updated', 400, 'aged');

    // Shortening the window makes more records *eligible* and moves none of them.
    // Only an operator's request does that (ADR 0037): a setting that could cause a
    // removal would be a scheduled cleanup wearing a configuration field's clothes.
    app(SettingServiceInterface::class)->set('operations', 'audit_retention_days', 1);

    expect(app(AuditArchivist::class)->eligible(1)->get()->pluck('subject'))->toContain('aged')
        ->and(AuditRecord::query()->where('subject', 'aged')->exists())->toBeTrue()
        ->and(AuditRecord::query()->where('action', AuditAction::AUDIT_ARCHIVED)->exists())->toBeFalse();
});

// ── Export, verify, remove — in that order ───────────────────────────────────

test('an archival writes a file, then removes what it wrote', function (): void {
    agedRecord('setting.updated', 400, 'first');
    agedRecord('secret.rotated', 500, 'second');
    agedRecord('setting.updated', 5, 'recent');

    $response = archiveTrail($this);

    $response->assertOk()
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.removed', true);

    $location = $response->json('data.location');

    Storage::disk('archives')->assertExists($location);

    $archive = json_decode((string) Storage::disk('archives')->get($location), true);

    expect($archive['count'])->toBe(2)
        ->and($archive['records'])->toHaveCount(2)
        // Removed from the active store, and only the eligible ones.
        ->and(AuditRecord::query()->where('subject', 'first')->exists())->toBeFalse()
        ->and(AuditRecord::query()->where('subject', 'second')->exists())->toBeFalse()
        ->and(AuditRecord::query()->where('subject', 'recent')->exists())->toBeTrue();
});

test('nothing eligible writes no file and removes nothing', function (): void {
    agedRecord('setting.updated', 5, 'recent');

    $response = archiveTrail($this);

    $response->assertOk()
        ->assertJsonPath('data.count', 0)
        ->assertJsonPath('data.removed', false)
        ->assertJsonPath('data.location', null);

    // An empty archive file would be an artefact implying a removal that never happened.
    expect(Storage::disk('archives')->allFiles())->toBe([])
        ->and(AuditRecord::query()->where('subject', 'recent')->exists())->toBeTrue();
});

test('an archive that cannot be read back removes nothing', function (): void {
    agedRecord('setting.updated', 400, 'first');

    // A disk that accepts a write and cannot return it. This is the failure the
    // verification step exists for, and the only way to prove the step gates the
    // removal rather than merely running before it.
    Storage::shouldReceive('disk')->andReturn($broken = Mockery::mock());
    $broken->shouldReceive('put')->andReturn(true);
    $broken->shouldReceive('get')->andReturn(null);

    $response = archiveTrail($this);

    $response->assertStatus(500)
        ->assertJsonPath('error.code', 'AUDIT_ARCHIVE_UNVERIFIED');

    // Still there. Nothing is removed that has not first been exported *and verified*.
    expect(AuditRecord::query()->where('subject', 'first')->exists())->toBeTrue();
});

test('an archive missing records removes nothing', function (): void {
    agedRecord('setting.updated', 400, 'first');

    // Byte-identical is not enough on its own; the file must actually contain what it
    // was given. A disk returning valid JSON describing fewer records fails here.
    Storage::shouldReceive('disk')->andReturn($broken = Mockery::mock());
    $broken->shouldReceive('put')->andReturn(true);
    $broken->shouldReceive('get')->andReturn('{"records":[]}');

    archiveTrail($this)->assertStatus(500);

    expect(AuditRecord::query()->where('subject', 'first')->exists())->toBeTrue();
});

test('an archive whose contents were altered removes nothing', function (): void {
    $id = agedRecord('setting.updated', 400, 'first');

    // Valid JSON, carrying the right identifiers, and not what was written. Only the
    // byte comparison sees this: the identifier check is satisfied, and so would any
    // check that asked "does the archive describe these records". Silent alteration
    // between the write and the read is exactly what an integrity step is for.
    Storage::shouldReceive('disk')->andReturn($tampering = Mockery::mock());
    $tampering->shouldReceive('put')->andReturn(true);
    $tampering->shouldReceive('get')->andReturn((string) json_encode([
        'records' => [['id' => $id, 'action' => 'setting.updated', 'outcome' => 'something-else']],
    ]));

    archiveTrail($this)->assertStatus(500);

    expect(AuditRecord::query()->where('subject', 'first')->exists())->toBeTrue();
});

// ── The archival records itself ──────────────────────────────────────────────

test('an archival writes its own record, with the window and the location', function (): void {
    agedRecord('setting.updated', 400, 'first');

    $response = archiveTrail($this);
    $response->assertOk();

    $record = AuditRecord::query()->where('action', AuditAction::AUDIT_ARCHIVED)->firstOrFail();

    expect($record->outcome)->toBe('succeeded')
        ->and($record->context['count'] ?? null)->toBe(1)
        ->and($record->context['location'] ?? null)->toBe($response->json('data.location'))
        ->and($record->context['window']['oldest'] ?? null)->not->toBeNull();
});

test('a failed archival is recorded as a failure rather than silently', function (): void {
    agedRecord('setting.updated', 400, 'first');

    Storage::shouldReceive('disk')->andReturn($broken = Mockery::mock());
    $broken->shouldReceive('put')->andReturn(true);
    $broken->shouldReceive('get')->andReturn(null);

    archiveTrail($this)->assertStatus(500);

    $record = AuditRecord::query()->where('action', AuditAction::AUDIT_ARCHIVED)->firstOrFail();

    expect($record->outcome)->toBe('failed');
});

test('a run that moved nothing is still recorded', function (): void {
    archiveTrail($this)->assertOk();

    // "An operator ran this and it moved nothing" is a different fact from "nobody ran
    // it", and only one of them is visible if unsuccessful runs go unrecorded.
    expect(AuditRecord::query()->where('action', AuditAction::AUDIT_ARCHIVED)->count())->toBe(1);
});

test('the archive carries no record content beyond what the trail already held', function (): void {
    agedRecord('secret.rotated', 400, 'mail.password');

    $response = archiveTrail($this);
    $response->assertOk();

    $raw = (string) Storage::disk('archives')->get($response->json('data.location'));

    // The trail never held a credential, so neither does the archive. Stated as a test
    // because an archive is a file, and a file travels further than a table.
    expect($raw)->toContain('mail.password')
        ->and($raw)->not->toContain('password"=>"')
        ->and($raw)->not->toContain('eyJpdiI6');
});

// ── Reading the trail ────────────────────────────────────────────────────────

test('the trail is readable, newest first', function (): void {
    agedRecord('setting.updated', 3, 'older');
    agedRecord('setting.updated', 1, 'newer');

    $response = $this->withToken(adminToken(roles: ['administrator']))
        ->getJson('/api/v1/admin/audit');

    $response->assertOk()
        ->assertJsonPath('data.0.subject', 'newer')
        ->assertJsonPath('data.1.subject', 'older');
});

test('the trail can be filtered without a scan over context', function (): void {
    agedRecord('setting.updated', 1, 'a');
    agedRecord('secret.rotated', 1, 'b');

    $this->withToken(adminToken(roles: ['administrator']))
        ->getJson('/api/v1/admin/audit?action=secret.rotated')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'b');
});

test('an empty filter reads as absent rather than as a value to match', function (): void {
    agedRecord('setting.updated', 1, 'a');

    // ?action= must mean "everything", not "records whose action is the empty string"
    // — of which there are none, making the difference between all and nothing.
    $this->withToken(adminToken(roles: ['administrator']))
        ->getJson('/api/v1/admin/audit?action=')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('every action the platform records resolves a label in both locales', function (): void {
    $actions = (new ReflectionClass(AuditAction::class))->getConstants();

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($actions as $action) {
            expect(__('audit.action.'.$action))->not->toBe('audit.action.'.$action, $action.' has no '.$locale.' label');
        }
    }
});

// ── Permissions ──────────────────────────────────────────────────────────────

test('reading the trail and archiving from it are different powers', function (): void {
    agedRecord('setting.updated', 400, 'first');

    // The accounts most interested in removal are the ones being recorded, so holding
    // audit.view is not a reason to hold audit.manage (ADR 0037).
    archiveTrail($this, token: tokenWithPermissions(['audit.view']))
        ->assertStatus(403);

    expect(AuditRecord::query()->where('subject', 'first')->exists())->toBeTrue();
});

test('the seeded administrator may read the trail and may not archive it', function (): void {
    agedRecord('setting.updated', 400, 'first');

    $token = adminToken(roles: ['administrator']);

    $this->withToken($token)->getJson('/api/v1/admin/audit')->assertOk();

    archiveTrail($this, token: $token)->assertStatus(403);
});

test('the audit endpoints are behind the admin perimeter', function (): void {
    $this->getJson('/api/v1/admin/audit')->assertStatus(401);
    resetClient($this);
    $this->postJson('/api/v1/admin/audit/archive')->assertStatus(401);
});

test('there is no endpoint that edits or removes a single record', function (): void {
    $routes = collect(Route::getRoutes())->map(fn ($r): string => $r->methods()[0].' '.$r->uri());

    $mutating = $routes->filter(fn (string $r): bool => str_contains($r, 'admin/audit')
        && (str_starts_with($r, 'PUT') || str_starts_with($r, 'PATCH') || str_starts_with($r, 'DELETE')));

    // A trail whose subjects can rewrite it is not a trail. Archival is the single
    // permitted removal path and it takes a window, never a record.
    expect($mutating->all())->toBe([]);
});
