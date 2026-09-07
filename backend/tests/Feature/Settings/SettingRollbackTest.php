<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Enums\SettingType;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Models\SettingRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->service = app(SettingServiceInterface::class);

    // Provisioning writes no revisions, but start from a clean slate regardless so a
    // count means what it says.
    SettingRevision::query()->getQuery()->delete();
});

/**
 * Roll a group back through the API, with a correct precondition unless one is given.
 */
function rollback(mixed $test, string $group, string $revisionId, ?string $token = null, ?string $ifMatch = null): mixed
{
    $token ??= adminToken(roles: ['super_admin']);
    $ifMatch ??= settingsVersion($group);

    // Between two requests in one test the guard caches whoever it resolved first, so
    // a second call with a different token would be answered as the first caller —
    // which silently turns a permission test into a test of nothing.
    resetClient($test);

    return $test->withToken($token)
        ->withHeader('If-Match', '"'.$ifMatch.'"')
        ->postJson('/api/v1/admin/settings/'.$group.'/rollback', ['revision_id' => $revisionId]);
}

/** The identifier of the newest revision recorded for a setting. */
function newestRevision(string $group, string $key, ?string $locale = null): string
{
    $setting = Setting::query()->where('group', $group)->where('key', $key)->firstOrFail();

    return (string) SettingRevision::query()
        ->where('setting_id', $setting->id)
        ->when($locale !== null, fn ($q) => $q->where('locale', $locale))
        ->orderByDesc('id')
        ->firstOrFail()
        ->id;
}

/**
 * The identifier of the revision at an ordinal position, oldest first.
 *
 * A batch write records one revision per setting, in payload order, so naming a point
 * in time by "the newest revision of key X" can land in the middle of a batch and
 * exclude the settings written before it. Counting rows before a batch and taking the
 * next one names the batch itself.
 */
function revisionAt(int $offset): string
{
    return (string) SettingRevision::query()->orderBy('id')->skip($offset)->take(1)->firstOrFail()->id;
}

/**
 * Replace one declaration and rebuild everything that reads declarations.
 *
 * This is how "the declaration as it exists today" is exercised: the value was written
 * under one declaration and is read back under another, which is exactly the situation
 * ADR 0040 requires a rollback to survive. The service is forgotten along with the
 * registry because it holds one.
 */
function redeclare(SettingDefinition ...$replacements): void
{
    $fresh = new SettingRegistry;
    $overrides = [];

    foreach ($replacements as $replacement) {
        $overrides[$replacement->reference()] = $replacement;
    }

    foreach (app(SettingRegistry::class)->all() as $reference => $definition) {
        $fresh->register($overrides[$reference] ?? $definition);
    }

    app()->instance(SettingRegistry::class, $fresh);
    app()->forgetInstance(SettingServiceInterface::class);
}

// ── Restoring ────────────────────────────────────────────────────────────────

test('a rollback puts back the value a setting held at the target', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $target = newestRevision('localization', 'date_format');
    $this->service->set('localization', 'date_format', 'm-d-Y');

    Cache::flush();

    $response = rollback($this, 'localization', $target);

    $response->assertOk()
        ->assertJsonPath('data.restored.0.key', 'date_format')
        ->assertJsonPath('data.restored.0.value', 'Y-m-d');

    // The target revision holds the value its own write superseded, which is the state
    // the group was in immediately before it — the seeded default, not 'd/m/Y'.
    expect(app(SettingServiceInterface::class)->get('localization.date_format'))->toBe('Y-m-d');
});

test('a rollback is a new change, not a rewrite of history', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $target = newestRevision('localization', 'date_format');
    $this->service->set('localization', 'date_format', 'm-d-Y');

    $before = SettingRevision::query()->count();
    $version = Setting::query()->where('group', 'localization')->where('key', 'date_format')->value('version');

    rollback($this, 'localization', $target)->assertOk();

    $setting = Setting::query()->where('group', 'localization')->where('key', 'date_format')->firstOrFail();

    expect(SettingRevision::query()->count())->toBe($before + 1)
        ->and($setting->version)->toBe($version + 1);
});

test('a rollback can itself be rolled back', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $target = newestRevision('localization', 'date_format');
    $this->service->set('localization', 'date_format', 'm-d-Y');

    rollback($this, 'localization', $target)->assertOk();

    // The rollback recorded what it superseded, so the state before it is reachable
    // the same way any other state is.
    $undo = newestRevision('localization', 'date_format');

    Cache::flush();
    rollback($this, 'localization', $undo)->assertOk();

    expect(app(SettingServiceInterface::class)->get('localization.date_format'))->toBe('m-d-Y');
});

test('a setting untouched since the target is left exactly as it is', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $target = newestRevision('localization', 'date_format');
    $this->service->set('localization', 'date_format', 'm-d-Y');

    // Never written, so it has no revision at or after the target and nothing to
    // restore. Rewriting it with the value it already holds would advance its counter
    // and move the group version for nothing.
    $untouched = Setting::query()->where('group', 'localization')->where('key', 'timezone')->firstOrFail();

    rollback($this, 'localization', $target)->assertOk();

    expect($untouched->fresh()?->version)->toBe($untouched->version)
        ->and(SettingRevision::query()->where('setting_id', $untouched->id)->count())->toBe(0);
});

test('a rollback to the state a group is already in writes nothing', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $this->service->set('localization', 'date_format', 'Y-m-d');

    // Back where it started, so restoring the first revision changes nothing.
    $target = SettingRevision::query()->orderBy('id')->firstOrFail()->id;

    $versionBefore = settingsVersion('localization');
    $revisionsBefore = SettingRevision::query()->count();
    AuditRecord::query()->getQuery()->delete();

    $response = rollback($this, 'localization', (string) $target);

    $response->assertOk()->assertJsonPath('data.restored', []);

    // A version that moves without a value moving invalidates every client's cached
    // read to store what was already there.
    expect(settingsVersion('localization'))->toBe($versionBefore)
        ->and(SettingRevision::query()->count())->toBe($revisionsBefore)
        // It still happened, so it is still recorded. An operation that restored
        // nothing is exactly the one whose reasons are worth having (ADR 0037).
        ->and(AuditRecord::query()->where('action', 'settings.rolled_back')->count())->toBe(1);
});

// ── Locales ──────────────────────────────────────────────────────────────────

test('a rollback restores one language and leaves the others alone', function (): void {
    app()->setLocale('en');
    $this->service->set('general', 'site_name', 'English One');
    $this->service->set('general', 'site_name', 'English Two');

    // The revision the second write recorded, which holds 'English One'. The first
    // write's revision holds null — English had no translation of its own before it —
    // and site_name is declared non-nullable, so that state is deliberately not
    // restorable.
    $target = newestRevision('general', 'site_name', 'en');
    $this->service->set('general', 'site_name', 'English Three');

    app()->setLocale('ar');
    $this->service->set('general', 'site_name', 'Arabic One');

    app()->setLocale('en');
    Cache::flush();

    rollback($this, 'general', $target)->assertOk();

    $setting = Setting::query()->where('group', 'general')->where('key', 'site_name')->with('translations')->firstOrFail();

    // English went back to what preceded 'English One'; Arabic was never part of the
    // target and is untouched.
    expect($setting->getTypedValue('en'))->toBe('English One')
        ->and($setting->getTypedValue('ar'))->toBe('Arabic One');
});

// ── Secrets ──────────────────────────────────────────────────────────────────

test('a credential is reported by key as unrestorable and everything else is restored', function (): void {
    $this->service->set('mail', 'host', 'smtp.one.test');
    $target = newestRevision('mail', 'host');
    $this->service->set('mail', 'host', 'smtp.two.test');
    $this->service->set('mail', 'password', 'a-real-credential');

    Cache::flush();

    $response = rollback($this, 'mail', $target);

    $response->assertOk()
        ->assertJsonPath('data.skipped.0.key', 'password')
        ->assertJsonPath('data.skipped.0.reason', 'secret');

    // The credential is untouched, the ordinary setting is restored, and no part of
    // the response carries a value for the secret.
    expect(app(SettingServiceInterface::class)->get('mail.password'))->toBe('a-real-credential')
        ->and(app(SettingServiceInterface::class)->get('mail.host'))->toBeNull()
        ->and(json_encode($response->json()))->not->toContain('a-real-credential');
});

test('a rollback writes no revision for a secret', function (): void {
    $this->service->set('mail', 'host', 'smtp.one.test');
    $target = newestRevision('mail', 'host');
    $this->service->set('mail', 'password', 'a-real-credential');

    rollback($this, 'mail', $target)->assertOk();

    $password = Setting::query()->where('group', 'mail')->where('key', 'password')->firstOrFail();

    expect(SettingRevision::query()->where('setting_id', $password->id)->count())->toBe(0);
});

// ── Preconditions and targeting ──────────────────────────────────────────────

test('a rollback without a precondition is refused', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $target = newestRevision('localization', 'date_format');

    $this->withToken(adminToken(roles: ['super_admin']))
        ->postJson('/api/v1/admin/settings/localization/rollback', ['revision_id' => $target])
        ->assertStatus(428)
        ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

    expect(app(SettingServiceInterface::class)->get('localization.date_format'))->toBe('d/m/Y');
});

test('a rollback built on a stale read is refused', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $target = newestRevision('localization', 'date_format');
    $stale = settingsVersion('localization');

    $this->service->set('localization', 'date_format', 'm-d-Y');

    rollback($this, 'localization', $target, ifMatch: $stale)
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'SETTING_VERSION_CONFLICT');

    expect(app(SettingServiceInterface::class)->get('localization.date_format'))->toBe('m-d-Y');
});

test('a revision belonging to another group is refused, and so is one that does not exist', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $foreign = newestRevision('localization', 'date_format');

    // Both answer identically. Distinguishing them would let a caller with rollback on
    // one group enumerate which revision identifiers exist in another.
    rollback($this, 'general', $foreign)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SETTING_REVISION_NOT_FOUND');

    rollback($this, 'general', (string) Str::ulid())
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SETTING_REVISION_NOT_FOUND');
});

test('a malformed target is refused before it reaches a query', function (): void {
    rollback($this, 'localization', 'not-a-ulid')->assertStatus(422);
});

test('an unknown group is a 404 rather than an empty rollback', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');

    $this->withToken(adminToken(roles: ['super_admin']))
        ->withHeader('If-Match', '"whatever"')
        ->postJson('/api/v1/admin/settings/nosuchgroup/rollback', ['revision_id' => newestRevision('localization', 'date_format')])
        ->assertStatus(404);
});

// ── Permissions ──────────────────────────────────────────────────────────────

test('settings.update alone does not permit a rollback', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $target = newestRevision('localization', 'date_format');
    $this->service->set('localization', 'date_format', 'm-d-Y');

    // Rollback is deliberately not implied by being allowed to change a value
    // (ADR 0040). Asserted against the permission itself rather than against a role,
    // so that seeding rollback onto a role — as administrator now is — cannot quietly
    // turn this into a test of nothing.
    rollback($this, 'localization', $target, token: tokenWithPermissions(['settings.view', 'settings.update']))
        ->assertStatus(403);

    expect(app(SettingServiceInterface::class)->get('localization.date_format'))->toBe('m-d-Y');
});

test('the seeded administrator may roll back', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $target = newestRevision('localization', 'date_format');
    $this->service->set('localization', 'date_format', 'm-d-Y');

    // Granting it widens what the role can do without moving a boundary: the per-key
    // check still runs, secrets are still unrestorable, and the operation is still
    // audited and still needs a precondition.
    rollback($this, 'localization', $target, token: adminToken(roles: ['administrator']))
        ->assertOk();

    expect(app(SettingServiceInterface::class)->get('localization.date_format'))->toBe('Y-m-d');
});

test('a seeded administrator still cannot roll back a guarded setting', function (): void {
    $this->service->set('operations', 'audit_retention_days', 400);
    $target = newestRevision('operations', 'audit_retention_days');
    $this->service->set('operations', 'audit_retention_days', 500);

    // The role holds rollback and not settings.security.update, so the per-key check
    // is what stands between it and a value it may not change. This is the assertion
    // that makes seeding the permission safe rather than merely convenient.
    rollback($this, 'operations', $target, token: adminToken(roles: ['administrator']))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');

    expect(app(SettingServiceInterface::class)->get('operations.audit_retention_days'))->toBe(500);
});

test('a rollback cannot change a guarded setting without the permission that guards it', function (): void {
    // audit_retention_days declares its own permission: how long the record of who did
    // what survives is not something `settings.update` may shorten (ADR 0037).
    $this->service->set('operations', 'audit_retention_days', 400);
    $target = newestRevision('operations', 'audit_retention_days');
    $this->service->set('operations', 'audit_retention_days', 500);

    // Holds rollback and nothing else. Reaching a guarded value through an endpoint
    // that did not check its permission is the escalation this closes.
    rollback($this, 'operations', $target, token: tokenWithPermissions(['settings.rollback']))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');

    expect(app(SettingServiceInterface::class)->get('operations.audit_retention_days'))->toBe(500);

    // The same rollback, by someone who holds the permission that guards it.
    rollback($this, 'operations', $target, token: tokenWithPermissions(['settings.rollback', 'settings.security.update']))
        ->assertOk();

    // The target revision holds the value its own write superseded, so the group
    // returns to the state it was in immediately before that write: the declared
    // default, not 400.
    expect(app(SettingServiceInterface::class)->get('operations.audit_retention_days'))
        ->toBe(app(SettingRegistry::class)->get('operations.audit_retention_days')->default);
});

// ── Revalidation against today's declarations ────────────────────────────────

test('a value that no longer fits the declared type is reported and skipped', function (): void {
    $this->service->set('branding', 'watermark_position', 'center');
    $target = newestRevision('branding', 'watermark_position');
    $this->service->set('branding', 'watermark_position', 'top-left');

    // Retyped after the value was stored. 'bottom-right' is not an integer, so the
    // history is no longer readable as the thing this setting now is.
    redeclare(new SettingDefinition(
        group: 'branding',
        key: 'watermark_position',
        type: SettingType::INTEGER,
        default: 1,
        nullable: false,
    ));

    $response = rollback($this, 'branding', $target);

    $response->assertOk()
        ->assertJsonPath('data.restored', [])
        ->assertJsonPath('data.skipped.0.key', 'watermark_position')
        ->assertJsonPath('data.skipped.0.reason', 'type_changed');

    expect(Setting::query()->where('group', 'branding')->where('key', 'watermark_position')->value('value'))
        ->toBe('top-left');
});

test('a value that no longer satisfies tightened rules is reported and skipped', function (): void {
    $this->service->set('branding', 'watermark_opacity', 90);
    $target = newestRevision('branding', 'watermark_opacity');
    $this->service->set('branding', 'watermark_opacity', 50);

    // 60 was valid when it was written and is not valid now.
    redeclare(new SettingDefinition(
        group: 'branding',
        key: 'watermark_opacity',
        type: SettingType::INTEGER,
        default: 10,
        nullable: false,
        rules: ['integer', 'between:1,20'],
    ));

    rollback($this, 'branding', $target)
        ->assertOk()
        ->assertJsonPath('data.restored', [])
        ->assertJsonPath('data.skipped.0.reason', 'invalid_today');

    expect(Setting::query()->where('group', 'branding')->where('key', 'watermark_opacity')->value('value'))
        ->toBe('50');
});

test('a rollback will not switch something on whose prerequisite it could not restore', function (): void {
    // watermark_enabled declares a prerequisite it can only satisfy if the
    // prerequisite is restorable. Here the prerequisite's rules have tightened, so it
    // is skipped — and switching the capability on regardless would leave a
    // configuration the platform cannot act on.
    redeclare(
        new SettingDefinition(
            group: 'branding',
            key: 'watermark_enabled',
            type: SettingType::BOOLEAN,
            default: false,
            nullable: false,
            dependsOn: ['branding.watermark_position'],
        ),
        new SettingDefinition(
            group: 'branding',
            key: 'watermark_position',
            type: SettingType::STRING,
            default: 'bottom-right',
            nullable: true,
        ),
    );

    $service = app(SettingServiceInterface::class);
    $service->updateGroup('branding', ['watermark_position' => 'center', 'watermark_enabled' => true]);

    // The batch that switched it off, named from its first row: targeting the newest
    // revision of one key would land inside the batch and exclude the key written
    // before it.
    $boundary = SettingRevision::query()->count();
    $service->updateGroup('branding', ['watermark_position' => null, 'watermark_enabled' => false]);
    $target = revisionAt($boundary);

    // Now the prerequisite cannot be put back.
    redeclare(new SettingDefinition(
        group: 'branding',
        key: 'watermark_position',
        type: SettingType::STRING,
        default: 'bottom-right',
        nullable: true,
        rules: ['string', 'in:top-left'],
    ));

    $response = rollback($this, 'branding', $target);

    $response->assertOk();

    $reasons = collect($response->json('data.skipped'))->pluck('reason', 'key');

    expect($reasons['watermark_position'] ?? null)->toBe('invalid_today')
        ->and($reasons['watermark_enabled'] ?? null)->toBe('dependency_unsatisfied')
        ->and(Setting::query()->where('group', 'branding')->where('key', 'watermark_enabled')->value('value'))
        ->toBe('false');
});

// ── Audit ────────────────────────────────────────────────────────────────────

test('a rollback is one audit record naming keys and reasons and no values', function (): void {
    $this->service->set('mail', 'host', 'smtp.one.test');
    $target = newestRevision('mail', 'host');
    $this->service->set('mail', 'host', 'smtp.two.test');
    $this->service->set('mail', 'password', 'a-real-credential');

    AuditRecord::query()->getQuery()->delete();

    rollback($this, 'mail', $target)->assertOk();

    $records = AuditRecord::query()->where('action', 'settings.rolled_back')->get();

    expect($records)->toHaveCount(1);

    $context = json_encode($records->first()?->context);

    expect($context)->toContain('host')
        ->and($context)->toContain('password')
        ->and($context)->toContain('secret')
        // What it must never carry: any value, restored or superseded (ADR 0037).
        ->and($context)->not->toContain('a-real-credential')
        ->and($context)->not->toContain('smtp.one.test')
        ->and($context)->not->toContain('smtp.two.test');
});

test('a revision range scan orders identifiers the way ULIDs are generated', function (): void {
    // The whole targeting design rests on "at or after the target" being a range scan
    // over the identifier. ULIDs are Crockford base32 — [0-9A-Z] — and a column
    // collation that sorted letters before digits would make that scan select the
    // wrong revisions, with no error and no visible symptom. Asserted against the
    // engine actually running, because it is a property of the database rather than
    // of the application.
    $setting = Setting::query()->where('group', 'localization')->where('key', 'date_format')->firstOrFail();

    $ids = ['0AAAAAAAAAAAAAAAAAAAAAAAAA', '9AAAAAAAAAAAAAAAAAAAAAAAAA', 'AAAAAAAAAAAAAAAAAAAAAAAAAA'];

    // Written through the query builder: the identifier is not fillable, and it must
    // not become fillable to make a test convenient.
    foreach ($ids as $index => $id) {
        DB::table('setting_revisions')->insert([
            'id' => $id,
            'setting_id' => $setting->id,
            'version' => $index,
            'locale' => null,
            'value' => 'v'.$index,
            'actor_id' => null,
            'created_at' => now(),
        ]);
    }

    expect(SettingRevision::query()->where('id', '>=', $ids[1])->orderBy('id')->pluck('id')->all())
        ->toBe([$ids[1], $ids[2]]);
});

// ── Adversarial ──────────────────────────────────────────────────────────────

test('a rollback cannot reach outside the group named in the path', function (): void {
    $this->service->set('localization', 'date_format', 'd/m/Y');
    $this->service->set('general', 'official_email', 'first@example.test');
    $target = newestRevision('general', 'official_email');
    $this->service->set('general', 'official_email', 'second@example.test');
    $this->service->set('localization', 'date_format', 'm-d-Y');

    Cache::flush();
    rollback($this, 'general', $target)->assertOk();

    // The other group moved after the target and is deliberately unaffected.
    expect(app(SettingServiceInterface::class)->get('localization.date_format'))->toBe('m-d-Y');
});

test('a traversal in the group segment does not reach a route', function (): void {
    $this->withToken(adminToken(roles: ['super_admin']))
        ->withHeader('If-Match', '"x"')
        ->postJson('/api/v1/admin/settings/..%2F..%2Fetc/rollback', ['revision_id' => (string) Str::ulid()])
        ->assertStatus(404);
});

test('the rollback endpoint is behind the admin perimeter', function (): void {
    $this->postJson('/api/v1/admin/settings/localization/rollback', ['revision_id' => (string) Str::ulid()])
        ->assertStatus(401);
});
