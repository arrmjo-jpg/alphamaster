<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->service = app(SettingServiceInterface::class);
    $this->token = adminToken(roles: ['super_admin']);
});

/** Configure mail to the point where a test message can be attempted. */
function configureMail(mixed $test): void
{
    $test->service->updateGroup('mail', [
        'enabled' => true,
        'host' => 'smtp.example.test',
        'port' => 587,
        'from_address' => 'platform@example.test',
        'test_recipient' => 'operator@example.test',
    ]);
}

/** Ask the platform to verify its mail configuration. */
function testMail(mixed $test): mixed
{
    return $test->withToken($test->token)->postJson('/api/v1/admin/settings/mail/test');
}

// ── An unfinished configuration is described, not attempted ──────────────────

test('an incomplete configuration reports what is missing rather than failing obscurely', function (): void {
    // Telling an operator the host is unset beats a connection error that means the
    // same thing.
    $response = testMail($this);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'MAIL_CONFIGURATION_INCOMPLETE');

    expect($response->json('error.details.missing'))->toContain('mail.enabled')
        ->and($response->json('error.details.missing'))->toContain('mail.host');
});

test('mail disabled is itself a missing requirement', function (): void {
    $this->service->updateGroup('mail', [
        'host' => 'smtp.example.test',
        'from_address' => 'platform@example.test',
        'test_recipient' => 'operator@example.test',
    ]);

    expect(testMail($this)->json('error.details.missing'))->toBe(['mail.enabled']);
});

// ── A configured platform really sends ───────────────────────────────────────

test('a complete configuration sends to the configured recipient', function (): void {
    Mail::fake();
    configureMail($this);

    // Asserted on the outcome rather than on MailFake's recording: the message is
    // sent with raw(), which carries no Mailable class for assertSent to match on.
    testMail($this)
        ->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.recipient', 'operator@example.test');
});

test('the recipient comes from settings and never from the request', function (): void {
    // Otherwise verifying a configuration becomes a way to send mail to an address
    // chosen by whoever called the endpoint.
    Mail::fake();
    configureMail($this);

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/settings/mail/test', ['recipient' => 'attacker@example.test'])
        ->assertOk()
        // The address the caller supplied is ignored entirely.
        ->assertJsonPath('data.recipient', 'operator@example.test');

    expect(setting('mail.test_recipient'))->toBe('operator@example.test');
});

// ── A failure is a failure, never a quiet success ────────────────────────────

test('a transport failure is reported as failed rather than as sent', function (): void {
    // The rule this whole operation exists for: a test that reported success without
    // delivering would be read as proof and stop an operator looking.
    configureMail($this);

    // No Mail::fake here, so the runtime mailer really attempts the unreachable host.
    $response = testMail($this);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'MAIL_TEST_FAILED')
        ->assertJsonPath('error.details.status', 'failed');

    expect($response->json('error.details.failure'))->toBeString()->not->toBeEmpty();
});

test('a failure names the exception class and not its message', function (): void {
    // A transport exception's message frequently carries the host, the username and
    // occasionally the credential it tried.
    configureMail($this);
    $this->service->set('mail', 'username', 'smtp-user');
    $this->service->set('mail', 'password', 'smtp-password-value');

    $response = testMail($this);
    $body = (string) $response->getContent();

    expect($body)->not->toContain('smtp-password-value')
        ->and($body)->not->toContain('smtp-user');
});

// ── Every attempt is recorded, and no credential is ─────────────────────────

test('a successful test is recorded', function (): void {
    Mail::fake();
    configureMail($this);

    testMail($this)->assertOk();

    $record = AuditRecord::query()->orderByDesc('id')->firstOrFail();

    expect($record->action)->toBe(AuditAction::MAIL_TEST_SENT)
        ->and($record->outcome)->toBe(AuditRecord::OUTCOME_SUCCEEDED)
        ->and($record->subject)->toBe('mail');
});

test('a failed test is recorded as failed', function (): void {
    configureMail($this);

    testMail($this)->assertStatus(422);

    $record = AuditRecord::query()->orderByDesc('id')->firstOrFail();

    expect($record->action)->toBe(AuditAction::MAIL_TEST_SENT)
        ->and($record->outcome)->toBe(AuditRecord::OUTCOME_FAILED);
});

test('no credential reaches the audit trail from a mail test', function (): void {
    configureMail($this);
    $this->service->set('mail', 'password', 'smtp-password-value');

    testMail($this);

    expect(json_encode(AuditRecord::query()->get()->toArray()))
        ->not->toContain('smtp-password-value');
});

// ── Authorization ───────────────────────────────────────────────────────────

test('sending a test needs the permission that changes configuration', function (): void {
    // It sends real mail from the platform's own address; reading settings is not
    // enough to do that.
    $this->withToken(adminToken(roles: ['editor']))
        ->postJson('/api/v1/admin/settings/mail/test')
        ->assertStatus(403);

    resetClient($this);

    $this->postJson('/api/v1/admin/settings/mail/test')->assertStatus(401);
});

test('the mail password is never returned by any settings endpoint', function (): void {
    configureMail($this);
    $this->service->set('mail', 'password', 'smtp-password-value');

    $group = $this->withToken($this->token)->getJson('/api/v1/admin/settings/mail');
    $all = $this->withToken($this->token)->getJson('/api/v1/admin/settings');
    $public = $this->getJson('/api/v1/settings');

    foreach ([$group, $all, $public] as $response) {
        expect((string) $response->getContent())->not->toContain('smtp-password-value');
    }

    // ...and it is still readable by the platform itself.
    expect($this->service->get('mail.password'))->toBe('smtp-password-value');
});
