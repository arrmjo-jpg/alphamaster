<?php

declare(strict_types=1);

use App\Modules\Integration\Contracts\CaptchaVerifierContract;
use App\Modules\Integration\Data\CaptchaChallenge;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Exceptions\NoProviderConfiguredException;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Integration\Services\Captcha\RecaptchaProvider;
use App\Modules\Integration\Services\CaptchaManager;
use App\Modules\Integration\Services\CaptchaVerifier;
use App\Modules\Integration\Services\SmsManager;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

beforeEach(function (): void {
    Cache::flush();
    $this->seed(IntegrationProviderSeeder::class);

    $this->verifier = app(CaptchaVerifierContract::class);
    $this->manager = app(CaptchaManager::class);
});

/**
 * Give reCAPTCHA a secret and switch it on, which is what an operator does.
 *
 * @param  array<string, mixed>  $settings
 */
function activateRecaptcha(array $settings = ['minimum_score' => null]): IntegrationProvider
{
    $provider = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::CAPTCHA)
        ->where('driver', 'recaptcha')
        ->firstOrFail();

    $provider->setCredentials(['secret_key' => 'test-secret']);
    $provider->forceFill(['settings' => $settings, 'is_active' => true])->save();

    return $provider->refresh();
}

/**
 * @param  array<string, mixed>  $body
 */
function fakeVerify(array $body, int $status = 200): void
{
    Http::fake([VERIFY_URL => Http::response($body, $status)]);
}

// ── Provisioning ─────────────────────────────────────────────────────────────

test('a fresh installation ships reCAPTCHA provisioned but switched off', function (): void {
    $provider = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::CAPTCHA)
        ->firstOrFail();

    expect($provider->driver)->toBe('recaptcha')
        ->and($provider->is_active)->toBeFalse()
        ->and($provider->hasCredentials())->toBeFalse();
});

test('no captcha provider is a misconfiguration, not a failed challenge', function (): void {
    // The seeded provider is inactive, so the chain is empty. Returning a failure
    // result here would report an endless stream of failed challenges on a platform
    // that simply never finished wiring its captcha up.
    expect(fn () => $this->verifier->verify(new CaptchaChallenge('any-token')))
        ->toThrow(NoProviderConfiguredException::class);
});

test('there is no captcha driver that passes without asking a vendor', function (): void {
    // SMS ships a log driver so a fresh installation can send. The equivalent here
    // would be a captcha that answers "pass" with no vendor behind it, which is the
    // absence of a captcha wearing its name.
    expect(IntegrationProvider::query()->forCapability(IntegrationCapability::CAPTCHA)->count())->toBe(1);
});

// ── Success ──────────────────────────────────────────────────────────────────

test('a token the vendor accepts is a pass', function (): void {
    activateRecaptcha();
    fakeVerify(['success' => true, 'hostname' => 'admin.example.com']);

    $result = $this->verifier->verify(new CaptchaChallenge('good-token'));

    expect($result->successful)->toBeTrue()
        ->and($result->driver)->toBe('recaptcha')
        ->and($result->reference)->toBe('admin.example.com')
        ->and($result->errorCode)->toBeNull();
});

test('the secret and the token go to the vendor, and the caller IP when there is one', function (): void {
    activateRecaptcha();
    fakeVerify(['success' => true]);

    $this->verifier->verify(new CaptchaChallenge('good-token', '203.0.113.9'));

    Http::assertSent(function ($request): bool {
        return $request->url() === VERIFY_URL
            && $request['secret'] === 'test-secret'
            && $request['response'] === 'good-token'
            && $request['remoteip'] === '203.0.113.9';
    });
});

test('the caller IP is omitted rather than sent empty when it is unknown', function (): void {
    activateRecaptcha();
    fakeVerify(['success' => true]);

    $this->verifier->verify(new CaptchaChallenge('good-token'));

    Http::assertSent(fn ($request): bool => ! array_key_exists('remoteip', $request->data()));
});

// ── Rejection ────────────────────────────────────────────────────────────────

test('a token the vendor rejects is a refusal carrying its reason', function (): void {
    activateRecaptcha();
    fakeVerify(['success' => false, 'error-codes' => ['invalid-input-response']]);

    $result = $this->verifier->verify(new CaptchaChallenge('bad-token'));

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('REJECTED')
        ->and($result->errorMessage)->toContain('invalid-input-response');
});

test('a rejection arrives as HTTP 200, so the status is never the verdict', function (): void {
    // Google answers 200 with success:false for a bad token. A driver that trusted
    // the status code would pass every rejected token.
    activateRecaptcha();
    fakeVerify(['success' => false, 'error-codes' => ['timeout-or-duplicate']], 200);

    expect($this->verifier->verify(new CaptchaChallenge('replayed'))->successful)->toBeFalse();
});

test('an HTTP error from the vendor is a refusal, not a pass', function (): void {
    activateRecaptcha();
    fakeVerify(['message' => 'nope'], 503);

    $result = $this->verifier->verify(new CaptchaChallenge('any'));

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('HTTP_503');
});

// ── Timeout and other transport failures ─────────────────────────────────────

test('an unreachable vendor is a refusal, because a captcha must not fail open', function (): void {
    activateRecaptcha();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $result = $this->verifier->verify(new CaptchaChallenge('any'));

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('TRANSPORT_ERROR')
        ->and($result->errorMessage)->toContain('timed out');
});

test('a timeout is distinguishable from a rejection in the record it leaves', function (): void {
    // Both refuse the request, and an operator has to be able to tell "the vendor is
    // down" from "clients are failing the challenge". The error code is what does it.
    activateRecaptcha();

    // One handler that changes behaviour between the two calls. A second Http::fake()
    // would not replace the first — the stubs accumulate and the earlier catch-all
    // still answers — so both attempts would have timed out and the test would have
    // passed for the wrong reason.
    $mode = 'timeout';

    Http::fake(function () use (&$mode) {
        if ($mode === 'timeout') {
            throw new ConnectionException('timed out');
        }

        return Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]);
    });

    $this->verifier->verify(new CaptchaChallenge('a'));

    $mode = 'rejected';
    $this->verifier->verify(new CaptchaChallenge('b'));

    // Ordered by the ULID primary key rather than by created_at, which can tie.
    $codes = IntegrationUsageLog::query()
        ->where('capability', IntegrationCapability::CAPTCHA->value)
        ->orderBy('id')
        ->pluck('error_code')
        ->all();

    expect($codes)->toBe(['TRANSPORT_ERROR', 'REJECTED']);
});

test('unreadable credentials refuse rather than reaching the vendor', function (): void {
    $provider = activateRecaptcha();

    // Corrupt the ciphertext the way a restore under the wrong APP_KEY would.
    DB::table('integration_providers')->where('id', $provider->id)->update(['credentials' => 'not-ciphertext']);

    Http::fake();

    $result = $this->verifier->verify(new CaptchaChallenge('any'));

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('CREDENTIALS_UNREADABLE');

    Http::assertNothingSent();
});

test('a provider with no secret refuses before making a request', function (): void {
    $provider = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::CAPTCHA)
        ->firstOrFail();
    $provider->forceFill(['is_active' => true])->save();

    Http::fake();

    $result = $this->verifier->verify(new CaptchaChallenge('any'));

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('MISCONFIGURED');

    Http::assertNothingSent();
});

// ── The v3 score ─────────────────────────────────────────────────────────────

test('a score above the configured minimum passes and is reported', function (): void {
    activateRecaptcha(['minimum_score' => 0.5]);
    fakeVerify(['success' => true, 'score' => 0.9, 'hostname' => 'admin.example.com']);

    $result = $this->verifier->verify(new CaptchaChallenge('good'));

    expect($result->successful)->toBeTrue()
        ->and($result->score)->toBe(0.9);
});

test('a score below the configured minimum is refused despite the vendor saying success', function (): void {
    // reCAPTCHA v3 answers success:true for any well-formed unused token and puts its
    // judgement in the score. Without this comparison a v3 provider admits everything.
    activateRecaptcha(['minimum_score' => 0.5]);
    fakeVerify(['success' => true, 'score' => 0.1]);

    $result = $this->verifier->verify(new CaptchaChallenge('bot'));

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('SCORE_BELOW_MINIMUM')
        ->and($result->score)->toBe(0.1);
});

test('a v2 response with no score is judged by the vendor verdict alone', function (): void {
    activateRecaptcha(['minimum_score' => 0.5]);
    fakeVerify(['success' => true]);

    expect($this->verifier->verify(new CaptchaChallenge('v2'))->successful)->toBeTrue();
});

test('no configured minimum means the vendor verdict stands', function (): void {
    activateRecaptcha(['minimum_score' => null]);
    fakeVerify(['success' => true, 'score' => 0.1]);

    expect($this->verifier->verify(new CaptchaChallenge('low'))->successful)->toBeTrue();
});

// ── Selection, and the absence of failover ───────────────────────────────────

test('the verifier selects through the same chain every capability uses', function (): void {
    activateRecaptcha();

    expect($this->manager->capability())->toBe(IntegrationCapability::CAPTCHA)
        ->and($this->manager->getDefaultDriver())->toBe('recaptcha')
        ->and($this->manager->providerChain()->pluck('driver')->all())->toBe(['recaptcha']);
});

test('an inactive provider is not selected, exactly as for any other capability', function (): void {
    $provider = activateRecaptcha();
    $provider->forceFill(['is_active' => false])->save();

    expect($this->manager->providerChain())->toBeEmpty();
});

test('a rejection is never retried against another provider', function (): void {
    // A captcha token is minted by one vendor for one site key. Handing it to a second
    // provider does not produce a second opinion, it produces a rejection that says
    // nothing about the client — so unlike SMS, the selected provider's answer is
    // final. This is the assertion that would fail if failover were copied across.
    activateRecaptcha();

    $second = IntegrationProvider::query()->create([
        'capability' => IntegrationCapability::CAPTCHA,
        'driver' => 'recaptcha_secondary',
        'label' => 'A second configured captcha provider',
        'settings' => null,
        'is_active' => true,
        'is_default' => false,
        'priority' => 10,
    ]);

    fakeVerify(['success' => false, 'error-codes' => ['invalid-input-response']]);

    $result = $this->verifier->verify(new CaptchaChallenge('bad'));

    expect($result->successful)->toBeFalse()
        ->and($result->driver)->toBe('recaptcha');

    // Exactly one attempt: one outbound request and one usage row, against the first
    // provider only.
    Http::assertSentCount(1);

    $logs = IntegrationUsageLog::query()
        ->where('capability', IntegrationCapability::CAPTCHA->value)
        ->get();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->integration_provider_id)->not->toBe($second->id);
});

// ── Usage recording ──────────────────────────────────────────────────────────

test('a successful verification is recorded against its provider', function (): void {
    $provider = activateRecaptcha();
    fakeVerify(['success' => true, 'hostname' => 'admin.example.com']);

    $this->verifier->verify(new CaptchaChallenge('good'));

    $log = IntegrationUsageLog::query()->firstOrFail();

    expect($log->capability)->toBe(IntegrationCapability::CAPTCHA)
        ->and($log->driver)->toBe('recaptcha')
        ->and($log->status)->toBe(UsageStatus::SUCCESS)
        ->and($log->integration_provider_id)->toBe($provider->id)
        ->and($log->reference)->toBe('admin.example.com')
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0);
});

test('a refusal is recorded too, so a failing vendor leaves evidence', function (): void {
    activateRecaptcha();
    fakeVerify(['success' => false, 'error-codes' => ['bad-request']]);

    $this->verifier->verify(new CaptchaChallenge('bad'));

    $log = IntegrationUsageLog::query()->firstOrFail();

    expect($log->status)->toBe(UsageStatus::FAILURE)
        ->and($log->error_code)->toBe('REJECTED');
});

test('the token is never written to the usage log', function (): void {
    // A usage log is for operating the integration. A captcha token is a credential
    // for as long as it lives, and an SMS body is not stored for the same reason.
    activateRecaptcha();
    fakeVerify(['success' => true]);

    $this->verifier->verify(new CaptchaChallenge('a-very-distinctive-token-value'));

    $row = (array) DB::table('integration_usage_logs')->first();

    expect(json_encode($row))->not->toContain('a-very-distinctive-token-value');
});

test('the secret is never written to the usage log or serialized', function (): void {
    $provider = activateRecaptcha();
    fakeVerify(['success' => true]);

    $this->verifier->verify(new CaptchaChallenge('good'));

    $row = (array) DB::table('integration_usage_logs')->first();

    expect(json_encode($row))->not->toContain('test-secret')
        ->and(json_encode($provider->toArray()))->not->toContain('test-secret')
        ->and($provider->toArray())->not->toHaveKey('credentials');
});

// ── The database still constrains the capability ─────────────────────────────

test('the widened constraint admits captcha and still refuses anything else', function (): void {
    // The capability column is constrained at the database level. Widening it for
    // captcha must not have turned the constraint off.
    expect(fn () => DB::table('integration_providers')->insert([
        'id' => (string) Str::ulid(),
        'capability' => 'telepathy',
        'driver' => 'x',
        'label' => 'x',
        'is_active' => false,
        'is_default' => false,
        'priority' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the two capabilities coexist, each with its own default', function (): void {
    // The single-default index is partial and per capability, so switching captcha on
    // does not disturb the SMS default.
    activateRecaptcha();

    expect(app(SmsManager::class)->defaultProvider()?->driver)->toBe('log')
        ->and($this->manager->defaultProvider()?->driver)->toBe('recaptcha');
});

// ── Architecture ─────────────────────────────────────────────────────────────

test('the driver answers to the name the manager resolves it by', function (): void {
    activateRecaptcha();

    expect(app(RecaptchaProvider::class)->driver())
        ->toBe($this->manager->getDefaultDriver());
});

test('the consumer-facing contract is what the container binds', function (): void {
    // A consumer depends on CaptchaVerifierContract, never on the manager or a driver.
    expect(app(CaptchaVerifierContract::class))->toBeInstanceOf(CaptchaVerifier::class);
});

test('the capability is labelled in both locales', function (): void {
    app()->setLocale('en');
    $english = IntegrationCapability::CAPTCHA->label();

    app()->setLocale('ar');
    $arabic = IntegrationCapability::CAPTCHA->label();

    expect($english)->not->toBe('captcha')
        ->and($arabic)->not->toBe('captcha')
        ->and($arabic)->not->toBe($english);
});

// ── The timeout an operator configured is the timeout the vendor gets ─────────

test('the driver waits as long as the platform is configured to wait', function (): void {
    $this->seed(SettingSeeder::class);
    app(SettingServiceInterface::class)->set('operations', 'provider_timeout_seconds', 42);
    Cache::flush();

    $seen = null;

    // The fake's second argument is the request options, which is where the timeout
    // lives. Asserting it here is the only way to see that the driver read the
    // setting at all: nothing about a faked response differs otherwise.
    Http::fake(function ($request, $options) use (&$seen) {
        $seen = $options['timeout'] ?? null;

        return Http::response(['success' => true, 'score' => 0.9]);
    });

    activateRecaptcha();
    $this->verifier->verify(new CaptchaChallenge('token', 'login', '127.0.0.1'));

    expect($seen)->toBe(42);
});

test('with no setting to read the driver falls back to the declared default', function (): void {
    // No settings seeded at all, which is what an unconfigured or unreachable
    // settings store looks like from inside a driver.
    $seen = null;

    Http::fake(function ($request, $options) use (&$seen) {
        $seen = $options['timeout'] ?? null;

        return Http::response(['success' => true, 'score' => 0.9]);
    });

    activateRecaptcha();
    $this->verifier->verify(new CaptchaChallenge('token', 'login', '127.0.0.1'));

    // Ten is `operations.provider_timeout_seconds`'s own default, so the fallback and
    // the configured value agree on a fresh installation.
    expect($seen)->toBe(10);
});
