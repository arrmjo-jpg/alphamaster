<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->settings = app(SettingServiceInterface::class);
    $this->audit = app(AuditRecorderContract::class);

    // Seeding writes records of its own; these assert what happens next.
    AuditRecord::query()->getQuery()->delete();
});

// ── The trail records the action ─────────────────────────────────────────────

test('a setting update is recorded with who, what, which and the outcome', function (): void {
    $admin = makeAccount(['email' => 'auditor@example.test']);
    $this->actingAs($admin);

    $this->settings->set('localization', 'date_format', 'd/m/Y');

    $record = AuditRecord::query()->orderByDesc('id')->firstOrFail();

    expect($record->action)->toBe(AuditAction::SETTING_UPDATED)
        ->and($record->subject)->toBe('localization.date_format')
        ->and($record->outcome)->toBe(AuditRecord::OUTCOME_SUCCEEDED)
        ->and($record->actor_id)->toBe($admin->id)
        ->and($record->created_at)->not->toBeNull();
});

test('an action with no signed-in actor is recorded honestly rather than attributed', function (): void {
    // A console command or the scheduler acts with no user. Recording null is better
    // than blaming whoever last ran a deploy.
    $this->settings->set('localization', 'date_format', 'Y/m/d');

    expect(AuditRecord::query()->orderByDesc('id')->firstOrFail()->actor_id)->toBeNull();
});

test('clearing a setting is a different action from updating it', function (): void {
    $this->settings->set('localization', 'date_format', null);

    expect(AuditRecord::query()->orderByDesc('id')->firstOrFail()->action)
        ->toBe(AuditAction::SETTING_CLEARED);
});

// ── Secrets: the trail knows that, never what ────────────────────────────────

test('setting a secret for the first time records set, and no value', function (): void {
    $this->settings->set('security', 'api_secret_key', 'first-credential');

    $record = AuditRecord::query()->orderByDesc('id')->firstOrFail();

    expect($record->action)->toBe(AuditAction::SECRET_SET)
        ->and($record->subject)->toBe('security.api_secret_key')
        ->and($record->context)->toBeNull();
});

test('replacing an existing secret records rotation', function (): void {
    $this->settings->set('security', 'api_secret_key', 'first-credential');
    $this->settings->set('security', 'api_secret_key', 'second-credential');

    expect(AuditRecord::query()->orderByDesc('id')->firstOrFail()->action)
        ->toBe(AuditAction::SECRET_ROTATED);
});

test('clearing a secret records that it was cleared', function (): void {
    $this->settings->set('security', 'api_secret_key', 'a-credential');
    $this->settings->set('security', 'api_secret_key', null);

    expect(AuditRecord::query()->orderByDesc('id')->firstOrFail()->action)
        ->toBe(AuditAction::SECRET_CLEARED);
});

test('no secret reaches the trail in any form', function (): void {
    // The rule with no exceptions: not plaintext, not ciphertext, not a partial
    // value, not a hash, not a length. A ciphertext here would be a second copy of
    // the credential under weaker access control than the settings table (ADR 0037).
    $plaintext = 'super-secret-credential-value';

    $this->settings->set('security', 'api_secret_key', $plaintext);

    $ciphertext = (string) Setting::query()
        ->where('group', 'security')->where('key', 'api_secret_key')->value('value');

    $everything = json_encode(AuditRecord::query()->get()->toArray());

    expect($everything)->not->toContain($plaintext)
        ->and($everything)->not->toContain($ciphertext)
        ->and($everything)->not->toContain(hash('sha256', $plaintext))
        ->and($everything)->not->toContain(md5($plaintext))
        // Not even the length, which for a short credential narrows it materially.
        ->and($everything)->not->toContain('"length"')
        // ...while the fact that it happened is recorded.
        ->and($everything)->toContain('security.api_secret_key');
});

test('a non-secret record describes the change without carrying the value', function (): void {
    $this->settings->set('localization', 'date_format', 'd-m-Y');

    $record = AuditRecord::query()->orderByDesc('id')->firstOrFail();

    expect($record->context)->toHaveKey('type')
        ->and(json_encode($record->context))->not->toContain('d-m-Y');
});

// ── Append-only ─────────────────────────────────────────────────────────────

test('a record cannot be updated or deleted through the application', function (): void {
    // A trail whose subjects can rewrite it is not a trail, and the accounts with
    // the most reason to are exactly the ones with administrative access.
    $record = $this->audit->succeeded(AuditAction::SETTING_UPDATED, 'general.site_name');

    expect(fn () => $record->update(['action' => 'something.else']))
        ->toThrow(RuntimeException::class, 'append-only');

    expect(fn () => $record->delete())
        ->toThrow(RuntimeException::class, 'append-only');

    expect(AuditRecord::query()->whereKey($record->id)->exists())->toBeTrue();
});

test('the table has no updated_at to revise', function (): void {
    expect(Schema::hasColumn('audit_records', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('audit_records', 'updated_at'))->toBeFalse();
});

// ── Failures are recorded too ───────────────────────────────────────────────

test('a failed action is recorded as failed rather than omitted', function (): void {
    // Most urgent for an external operation: an operation that failed silently
    // leaves an interface reporting success over a state nobody fixed.
    $this->audit->failed(AuditAction::MAIL_TEST_SENT, 'mail', ['reason' => 'connection_refused']);

    $record = AuditRecord::query()->orderByDesc('id')->firstOrFail();

    expect($record->outcome)->toBe(AuditRecord::OUTCOME_FAILED)
        ->and($record->context)->toBe(['reason' => 'connection_refused']);
});

// ── Retention is decided, not implicit ──────────────────────────────────────

test('audit retention is a configured decision with a documented default', function (): void {
    // ADR 0037 left the window open; this is where it is settled.
    expect(setting('operations.audit_retention_days'))->toBe(365);
});

test('changing retention needs the security permission, not ordinary settings update', function (): void {
    // How long the record of who did what survives is itself a security decision.
    $definition = app(SettingRegistry::class)
        ->get('operations.audit_retention_days');

    expect($definition->permission)->toBe('settings.security.update');
});

test('nothing in the application prunes the trail', function (): void {
    // The setting records intent. Acting on it is an operator's decision taken
    // against the database, because a scheduled job that silently deleted audit rows
    // would be the one deletion path ADR 0037 forbids.
    $sources = [];

    foreach (glob(app_path('Modules/*/Console/*.php')) ?: [] as $file) {
        $sources[] = (string) file_get_contents($file);
    }

    foreach ($sources as $source) {
        expect($source)->not->toContain('AuditRecord');
    }
});

// ── Reading the trail is its own permission ─────────────────────────────────

test('viewing the audit trail is a distinct permission from performing the actions', function (): void {
    $permissions = AdminPermission::values();

    expect($permissions)->toContain('audit.view')
        ->and($permissions)->toContain('settings.update')
        // Holding one must not imply the other.
        ->and('audit.view')->not->toBe('settings.update');
});
