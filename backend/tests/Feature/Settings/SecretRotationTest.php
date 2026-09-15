<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SecretVerifierContract;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Models\SettingRevision;
use App\Modules\Settings\Secrets\SecretVerificationResult;
use App\Modules\Settings\Secrets\SecretVerifierRegistry;
use App\Modules\Settings\Services\SecretRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/** The credential used throughout, so one grep proves it never leaks. */
const CANDIDATE = 'candidate-credential-8Kd92LmQ';

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->service = app(SettingServiceInterface::class);

    SettingRevision::query()->getQuery()->delete();
});

/** Rotate a credential through the API, with a correct precondition unless one is given. */
function rotate(mixed $test, string $group, string $key, string $value = CANDIDATE, ?string $token = null, ?string $ifMatch = null): mixed
{
    $token ??= adminToken(roles: ['super_admin']);
    $ifMatch ??= settingsVersion($group);

    resetClient($test);

    return $test->withToken($token)
        ->withHeader('If-Match', '"'.$ifMatch.'"')
        ->postJson('/api/v1/admin/settings/'.$group.'/secrets/'.$key.'/rotate', ['credential' => $value]);
}

/**
 * A verifier that returns a fixed answer and remembers what it was asked about.
 *
 * Named rather than anonymous so a test can read the candidate back afterwards, which
 * is the only way to prove the value being tried is the one that was submitted — and
 * not, say, the one already stored.
 */
final class RecordingVerifier implements SecretVerifierContract
{
    public static ?string $candidate = null;

    public function __construct(
        private readonly string $reference,
        private readonly SecretVerificationResult $result,
    ) {}

    public function reference(): string
    {
        return $this->reference;
    }

    public function verify(string $candidate): SecretVerificationResult
    {
        self::$candidate = $candidate;

        return $this->result;
    }
}

/**
 * Install a verifier for one reference, replacing whatever the platform registered.
 *
 * The real mail verifier opens an SMTP connection; these tests are about the rotation
 * contract rather than about SMTP, so the vendor's answer is the thing being controlled.
 */
function fakeVerifier(string $reference, SecretVerificationResult $result): void
{
    RecordingVerifier::$candidate = null;

    $registry = new SecretVerifierRegistry;
    $registry->register(new RecordingVerifier($reference, $result));

    app()->instance(SecretVerifierRegistry::class, $registry);
    app()->forgetInstance(SecretRotationService::class);
}

/** The stored ciphertext, read without decrypting it. */
function storedCipher(string $group, string $key): ?string
{
    return Setting::query()->where('group', $group)->where('key', $key)->value('value');
}

// ── Committing ───────────────────────────────────────────────────────────────

test('a secret with no verifier rotates and says nothing checked it', function (): void {
    // security.api_secret_key is internal; there is nothing to call, which is the
    // common case rather than a gap.
    $response = rotate($this, 'security', 'api_secret_key');

    $response->assertOk()
        ->assertJsonPath('data.verification.status', 'unavailable');

    expect(app(SettingServiceInterface::class)->get('security.api_secret_key'))->toBe(CANDIDATE);
});

test('a verified credential is committed', function (): void {
    fakeVerifier('mail.password', SecretVerificationResult::verified());

    rotate($this, 'mail', 'password')
        ->assertOk()
        ->assertJsonPath('data.verification.status', 'verified');

    expect(app(SettingServiceInterface::class)->get('mail.password'))->toBe(CANDIDATE);
});

test('the candidate reaches the verifier as the value being tried', function (): void {
    $this->service->set('mail', 'password', 'the-existing-credential');

    fakeVerifier('mail.password', SecretVerificationResult::verified());

    rotate($this, 'mail', 'password')->assertOk();

    // The value submitted, not the value already stored. A verifier handed the stored
    // credential would pass every rotation and prove nothing, and the failure would be
    // invisible because the result would look identical.
    expect(RecordingVerifier::$candidate)->toBe(CANDIDATE);
});

test('rotating advances the group version', function (): void {
    $before = settingsVersion('security');

    rotate($this, 'security', 'api_secret_key')->assertOk();

    expect(settingsVersion('security'))->not->toBe($before);
});

// ── Refusing ─────────────────────────────────────────────────────────────────

test('a rejected credential is not stored and the old one is left exactly as it was', function (): void {
    $this->service->set('mail', 'password', 'the-existing-credential');
    $before = storedCipher('mail', 'password');

    fakeVerifier('mail.password', SecretVerificationResult::failed('SmtpAuthenticationFailed'));

    rotate($this, 'mail', 'password')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SECRET_VERIFICATION_FAILED');

    // Not cleared, not replaced, not partially applied — the same ciphertext, byte for
    // byte (ADR 0038).
    expect(storedCipher('mail', 'password'))->toBe($before)
        ->and(app(SettingServiceInterface::class)->get('mail.password'))->toBe('the-existing-credential');
});

test('a rejected credential does not advance the version', function (): void {
    $this->service->set('mail', 'password', 'the-existing-credential');
    $before = settingsVersion('mail');

    fakeVerifier('mail.password', SecretVerificationResult::failed('SmtpAuthenticationFailed'));

    rotate($this, 'mail', 'password')->assertStatus(422);

    expect(settingsVersion('mail'))->toBe($before);
});

test('a refused rotation is recorded as a failed attempt', function (): void {
    AuditRecord::query()->getQuery()->delete();

    fakeVerifier('mail.password', SecretVerificationResult::failed('SmtpAuthenticationFailed'));

    rotate($this, 'mail', 'password')->assertStatus(422);

    $record = AuditRecord::query()->where('action', 'secret.rotated')->firstOrFail();

    // Recorded, because an attempt that reached a vendor is an event (ADR 0037) — and
    // recording only successes would leave the trail unable to show a credential being
    // guessed at.
    expect($record->outcome)->toBe('failed')
        ->and($record->subject)->toBe('mail.password');
});

// ── Saying which refusal it was ──────────────────────────────────────────────
//
// Two refusals used to share one sentence — "not accepted" — so an operator whose
// configuration was simply unsaved went looking for a password problem. These hold the
// two apart.

test('an incomplete configuration names the settings to save first', function (): void {
    fakeVerifier('mail.password', SecretVerificationResult::incomplete(['mail.host', 'mail.from_address']));

    $response = rotate($this, 'mail', 'password')
        ->assertStatus(422)
        // The same code either way, so a client branching on it keeps working.
        ->assertJsonPath('error.code', 'SECRET_VERIFICATION_FAILED')
        ->assertJsonPath('error.details.missing', ['mail.host', 'mail.from_address']);

    $registry = app(SettingRegistry::class);
    $message = (string) $response->json('error.message');

    // Named by the labels the Settings screen shows, because that is where the operator
    // is going to look for them.
    expect($message)->toContain($registry->get('mail.host')->label())
        ->and($message)->toContain($registry->get('mail.from_address')->label())
        ->and($message)->not->toBe(__('api.error.settings.secret_verification_failed'));
});

test('an incomplete configuration is named in the language of the reader', function (): void {
    fakeVerifier('mail.password', SecretVerificationResult::incomplete(['mail.host', 'mail.from_address']));

    resetClient($this);

    $response = $this->withToken(adminToken(roles: ['super_admin']))
        ->withHeader('If-Match', '"'.settingsVersion('mail').'"')
        ->withHeader('X-Locale', 'ar')
        ->postJson('/api/v1/admin/settings/mail/secrets/password/rotate', ['credential' => CANDIDATE])
        ->assertStatus(422);

    // The Arabic labels the Settings screen shows, joined by the catalogue's separator —
    // not an English name inside an Arabic sentence, and not a Latin comma.
    app()->setLocale('ar');
    $registry = app(SettingRegistry::class);
    $expected = $registry->get('mail.host')->label()
        .__('list.separator')
        .$registry->get('mail.from_address')->label();

    expect((string) $response->json('error.message'))->toContain($expected)
        ->and($registry->get('mail.host')->label())->not->toBe('Host');
});

test('a rejected credential is not reported as an incomplete configuration', function (): void {
    fakeVerifier('mail.password', SecretVerificationResult::failed('TransportException'));

    $response = rotate($this, 'mail', 'password')
        ->assertStatus(422)
        ->assertJsonPath('error.details.detail', 'TransportException');

    expect($response->json('error.details'))->not->toHaveKey('missing')
        ->and($response->json('error.message'))->toBe(__('api.error.settings.secret_verification_failed'));
});

test('the mail verifier reports a missing prerequisite rather than a rejection', function (): void {
    // The real verifier, not a fake. Its prerequisite check runs before any connection
    // is attempted, so this opens no socket — and the seeded mail configuration has no
    // host, no sender and no test recipient, and is switched off.
    $response = rotate($this, 'mail', 'password')->assertStatus(422);

    expect($response->json('error.details.missing'))
        ->toContain('mail.enabled')
        ->toContain('mail.host')
        ->toContain('mail.from_address')
        ->toContain('mail.test_recipient');
});

test('a refused rotation records what was missing, and never a value', function (): void {
    AuditRecord::query()->getQuery()->delete();

    fakeVerifier('mail.password', SecretVerificationResult::incomplete(['mail.host']));

    rotate($this, 'mail', 'password')->assertStatus(422);

    $record = AuditRecord::query()->where('action', 'secret.rotated')->firstOrFail();

    expect($record->outcome)->toBe('failed')
        ->and($record->context['missing'])->toBe(['mail.host'])
        ->and(json_encode($record->context))->not->toContain(CANDIDATE);
});

// ── Nothing leaks ────────────────────────────────────────────────────────────

test('no credential appears in the response, the trail or the revision store', function (): void {
    $this->service->set('mail', 'password', 'the-existing-credential');

    fakeVerifier('mail.password', SecretVerificationResult::verified());

    $response = rotate($this, 'mail', 'password');

    $response->assertOk();

    $trail = json_encode(AuditRecord::query()->get()->toArray());
    $revisions = json_encode(SettingRevision::query()->get()->toArray());
    $body = json_encode($response->json());

    foreach ([$body, $trail, $revisions] as $haystack) {
        expect($haystack)->not->toContain(CANDIDATE)
            ->and($haystack)->not->toContain('the-existing-credential');
    }
});

test('a rotated secret writes no revision at all', function (): void {
    $this->service->set('mail', 'password', 'the-existing-credential');
    SettingRevision::query()->getQuery()->delete();

    fakeVerifier('mail.password', SecretVerificationResult::verified());

    rotate($this, 'mail', 'password')->assertOk();

    $setting = Setting::query()->where('group', 'mail')->where('key', 'password')->firstOrFail();

    // Not a redacted revision, not a null placeholder: nothing. A rotated credential is
    // unrecoverable by design, which is what makes the revision store safe to keep
    // (ADR 0040).
    expect(SettingRevision::query()->where('setting_id', $setting->id)->count())->toBe(0);
});

test('the trail distinguishes a confirmed rotation from an unconfirmed one', function (): void {
    AuditRecord::query()->getQuery()->delete();

    rotate($this, 'security', 'api_secret_key')->assertOk();

    $record = AuditRecord::query()->where('action', 'secret.set')->firstOrFail();

    expect($record->context['verification'] ?? null)->toBe('unavailable');
});

// ── Preconditions, targeting and permissions ─────────────────────────────────

test('a rotation without a precondition is refused', function (): void {
    $this->withToken(adminToken(roles: ['super_admin']))
        ->postJson('/api/v1/admin/settings/security/secrets/api_secret_key/rotate', ['credential' => CANDIDATE])
        ->assertStatus(428);

    expect(storedCipher('security', 'api_secret_key'))->toBeNull();
});

test('a rotation built on a stale read is refused', function (): void {
    $stale = settingsVersion('security');
    $this->service->set('security', 'max_login_attempts', 7);

    rotate($this, 'security', 'api_secret_key', ifMatch: $stale)->assertStatus(412);

    expect(storedCipher('security', 'api_secret_key'))->toBeNull();
});

test('a setting that is not a secret cannot be rotated', function (): void {
    // Answers as an unknown key does: rotation is defined for credentials, and which
    // keys are secret is already readable from the definitions endpoint.
    rotate($this, 'security', 'max_login_attempts')->assertStatus(404);

    expect(app(SettingServiceInterface::class)->get('security.max_login_attempts'))->not->toBe(CANDIDATE);
});

test('an unknown key cannot be rotated', function (): void {
    rotate($this, 'security', 'no_such_key')->assertStatus(404);
});

test('an empty credential is refused before anything is attempted', function (): void {
    rotate($this, 'security', 'api_secret_key', value: '')->assertStatus(422);

    expect(storedCipher('security', 'api_secret_key'))->toBeNull();
});

test('settings.update does not permit rotating a credential', function (): void {
    // Every write to a secret needs settings.secrets.manage, and rotation is a write to
    // a secret performed more carefully — not a lesser one.
    rotate($this, 'security', 'api_secret_key', token: tokenWithPermissions(['settings.view', 'settings.update']))
        ->assertStatus(403);

    expect(storedCipher('security', 'api_secret_key'))->toBeNull();
});

test('the seeded administrator cannot rotate a credential', function (): void {
    rotate($this, 'security', 'api_secret_key', token: adminToken(roles: ['administrator']))
        ->assertStatus(403);
});

test('the rotation endpoint is behind the admin perimeter', function (): void {
    $this->postJson('/api/v1/admin/settings/security/secrets/api_secret_key/rotate', ['credential' => CANDIDATE])
        ->assertStatus(401);
});
