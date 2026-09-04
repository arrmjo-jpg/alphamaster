<?php

declare(strict_types=1);

use App\Modules\Auth\Exceptions\AccountInactiveException;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Exceptions\MfaChallengeException;
use App\Modules\Auth\Exceptions\MfaDeliveryException;
use App\Modules\Auth\Exceptions\MfaEnrolmentException;
use App\Modules\Auth\Exceptions\MfaSecretDecryptionException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Authorization\Exceptions\NotAnAdminAccountException;
use App\Modules\Core\Contracts\LocalizableException;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Exceptions\CredentialDecryptionException;
use App\Modules\Integration\Exceptions\NoProviderConfiguredException;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Media\Exceptions\MediaValidationException;
use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Exceptions\MissingTemplateException;
use App\Modules\Notification\Exceptions\PreferenceNotSilenceableException;
use App\Modules\Settings\Exceptions\SettingDecryptionException;
use App\Modules\Settings\Exceptions\SettingGroupNotFoundException;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
});

/**
 * Every exception this scope localizes, with the parameters it carries.
 *
 * @return array<string, LocalizableException>
 */
function localizedExceptions(): array
{
    return [
        'invalid credentials' => new InvalidCredentialsException,
        'account inactive' => new AccountInactiveException,
        'mfa challenge' => new MfaChallengeException('api.error.auth.mfa_challenge_expired'),
        'mfa delivery' => new MfaDeliveryException('api.error.auth.mfa_delivery_throttled'),
        'mfa enrolment' => new MfaEnrolmentException('api.error.auth.mfa_already_enabled'),
        'mfa enrolment with a type' => new MfaEnrolmentException(
            'api.error.auth.mfa_driver_missing',
            ['type' => 'totp']
        ),
        'too many attempts' => new TooManyAttemptsException(90),
        'not an admin' => new NotAnAdminAccountException('01JABCDEF0123456789ABCDEFG'),
        'media rejected' => new MediaValidationException('empty_file', 'api.error.media.empty_file'),
        'media too large' => new MediaValidationException(
            'too_large',
            'api.error.media.too_large',
            ['bytes' => 5242880]
        ),
        'settings group' => new SettingGroupNotFoundException('general'),
        'settings key' => new UnknownSettingKeyException('general', 'site_name'),
        'preference not silenceable' => new PreferenceNotSilenceableException(
            NotificationType::SECURITY_ALERT,
            NotificationChannel::MAIL
        ),
    ];
}

/**
 * The exceptions that report a technical fault and must stay untranslated.
 *
 * @return array<string, Throwable>
 */
function technicalExceptions(): array
{
    return [
        'mfa secret' => new MfaSecretDecryptionException('01JUSER', 'totp'),
        'integration credentials' => new CredentialDecryptionException('01JPROV', 'twilio'),
        'setting ciphertext' => new SettingDecryptionException('mail', 'password'),
        'missing template' => new MissingTemplateException(NotificationType::SECURITY_ALERT),
        'no provider' => new NoProviderConfiguredException(IntegrationCapability::SMS),
    ];
}

// ── Every localized exception resolves in both languages ─────────────────────

test('each localizable exception has a key that resolves in English and Arabic', function (): void {
    $cases = localizedExceptions();

    expect($cases)->toHaveCount(13);

    foreach ($cases as $name => $exception) {
        $key = $exception->translationKey();

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $rendered = __($key, $exception->translationParameters());

            expect($rendered)->toBeString()
                ->and($rendered)->not->toBe($key, $name.' is untranslated in '.$locale)
                ->and($rendered)->not->toStartWith('api.');
        }
    }
});

test('every localizable message differs between the two languages', function (): void {
    foreach (localizedExceptions() as $name => $exception) {
        app()->setLocale('en');
        $english = __($exception->translationKey(), $exception->translationParameters());

        app()->setLocale('ar');
        $arabic = __($exception->translationKey(), $exception->translationParameters());

        expect($arabic)->not->toBe($english, $name.' reads identically in both languages');
    }
});

// ── Replacements survive translation ─────────────────────────────────────────

test('every replacement is filled in both languages', function (): void {
    $expected = [
        ['api.error.auth.too_many_attempts', ['seconds' => 90], '90'],
        ['api.error.auth.mfa_driver_missing', ['type' => 'totp'], 'totp'],
        ['api.error.auth.mfa_admin_policy', ['type' => 'sms_otp'], 'sms_otp'],
        ['api.error.authorization.not_an_admin', ['id' => '01JABC'], '01JABC'],
        ['api.error.media.too_large', ['bytes' => 5242880], '5242880'],
        ['api.error.settings.group_not_found', ['group' => 'general'], 'general'],
    ];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($expected as [$key, $parameters, $needle]) {
            $rendered = __($key, $parameters);

            expect($rendered)->toContain($needle)
                ->and($rendered)->not->toContain(':');
        }
    }
});

test('a message with two replacements fills both, in both languages', function (): void {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        $setting = __('api.error.settings.unknown_key', ['group' => 'mail', 'key' => 'host']);
        $preference = __('api.error.notification.preference_not_silenceable', [
            'type' => 'security.alert',
            'channel' => 'mail',
        ]);

        expect($setting)->toContain('mail')->toContain('host')->not->toContain(':group')
            ->and($preference)->toContain('security.alert')->toContain('mail')->not->toContain(':channel');
    }
});

// ── getMessage() is English, whatever the request is ─────────────────────────

test('getMessage stays English while the locale is Arabic', function (): void {
    app()->setLocale('ar');

    expect(app()->getLocale())->toBe('ar');

    foreach (localizedExceptions() as $name => $exception) {
        expect($exception->getMessage())
            ->toBe(__($exception->translationKey(), $exception->translationParameters(), 'en'), $name);
    }
});

test('the English message is stable for the diagnostics that persist it', function (): void {
    // An SMS usage row and a media record store getMessage() from a queue
    // worker, where the locale is whatever the worker last handled. Those
    // columns must not change language between two identical failures.
    app()->setLocale('ar');
    $inArabicRequest = new SettingGroupNotFoundException('general');

    app()->setLocale('en');
    $inEnglishRequest = new SettingGroupNotFoundException('general');

    expect($inArabicRequest->getMessage())->toBe($inEnglishRequest->getMessage())
        ->and($inArabicRequest->getMessage())->toBe('Settings group [general] does not exist.');
});

test('a parameter reaches the English message too', function (): void {
    app()->setLocale('ar');

    expect((new TooManyAttemptsException(45))->getMessage())
        ->toBe('Too many attempts. Please retry in 45 seconds.')
        ->and((new UnknownSettingKeyException('mail', 'host'))->getMessage())
        ->toBe('Setting [mail.host] does not exist. Cannot update an unknown setting.');
});

// ── Technical exceptions are untouched ───────────────────────────────────────

test('the five technical exceptions carry no translation key', function (): void {
    $cases = technicalExceptions();

    expect($cases)->toHaveCount(5);

    foreach ($cases as $name => $exception) {
        expect($exception)->not->toBeInstanceOf(LocalizableException::class, $name.' must stay technical');
    }
});

test('technical exception messages are identical in every locale', function (): void {
    app()->setLocale('ar');
    $arabic = array_map(static fn (Throwable $e): string => $e->getMessage(), technicalExceptions());

    app()->setLocale('en');
    $english = array_map(static fn (Throwable $e): string => $e->getMessage(), technicalExceptions());

    expect($arabic)->toBe($english)
        ->and($arabic['setting ciphertext'])->toContain('APP_KEY');
});

// ── The API surface ──────────────────────────────────────────────────────────

test('a rejected login answers in the requested language', function (): void {
    $user = makeAccount(['email' => 'person@example.test']);

    $response = $this->withHeaders(['X-Locale' => 'ar'])->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'not-the-password',
    ]);

    expect($response->status())->toBe(401)
        ->and($response->json('error.code'))->toBe('INVALID_CREDENTIALS')
        ->and($response->json('error.message'))->toBe('بيانات الاعتماد المقدَّمة غير صحيحة.');
});

test('the same login reads in English', function (): void {
    $user = makeAccount(['email' => 'person@example.test']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'not-the-password',
    ]);

    expect($response->json('error.message'))->toBe('The provided credentials are incorrect.');
});

test('error code is unchanged across locales for an exception-backed error', function (): void {
    $user = makeAccount(['email' => 'person@example.test']);

    foreach (['en', 'ar', 'fr'] as $locale) {
        resetClient($this);

        $response = $this->withHeaders(['X-Locale' => $locale])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        expect($response->json('error.code'))->toBe('INVALID_CREDENTIALS', 'code moved in '.$locale);
    }
});

test('wrong password and unknown email stay indistinguishable in every locale', function (): void {
    // The enumeration defence: one key serves both causes, so localizing cannot
    // pull the two messages apart.
    $user = makeAccount(['email' => 'person@example.test']);

    foreach (['en', 'ar'] as $locale) {
        resetClient($this);
        $wrongPassword = $this->withHeaders(['X-Locale' => $locale])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        resetClient($this);
        $unknownEmail = $this->withHeaders(['X-Locale' => $locale])->postJson('/api/v1/auth/login', [
            'email' => 'ghost@example.test',
            'password' => 'not-the-password',
        ]);

        expect($wrongPassword->json('error.message'))
            ->toBe($unknownEmail->json('error.message'), 'the two causes diverge in '.$locale)
            ->and($wrongPassword->json('error.code'))->toBe($unknownEmail->json('error.code'));
    }
});

test('a suspended account is told so in its own language', function (): void {
    $user = makeAccount(['email' => 'suspended@example.test']);
    $user->forceFill(['is_active' => false])->save();

    $response = $this->withHeaders(['X-Locale' => 'ar'])->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => TEST_ACCOUNT_PASSWORD,
    ]);

    expect($response->status())->toBe(403)
        ->and($response->json('error.code'))->toBe('ACCOUNT_SUSPENDED')
        ->and($response->json('error.message'))->toBe('تم تعليق حسابك أو إلغاء تفعيله.');
});

test('a settings group error carries its group name in Arabic', function (): void {
    $response = $this->withHeaders(['Authorization' => 'Bearer '.adminToken(), 'X-Locale' => 'ar'])
        ->getJson('/api/v1/admin/settings/no_such_group');

    expect($response->status())->toBe(404)
        ->and($response->json('error.code'))->toBe('SETTING_GROUP_NOT_FOUND')
        ->and($response->json('error.message'))->toContain('no_such_group')
        ->and($response->json('error.message'))->toContain('غير موجودة');
});

test('no error response ever leaks a translation key', function (): void {
    $user = makeAccount(['email' => 'person@example.test']);

    $responses = [
        $this->withHeaders(['X-Locale' => 'ar'])->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'wrong',
        ]),
        $this->withHeaders(['X-Locale' => 'ar'])->getJson('/api/v1/no-such-route'),
        $this->withHeaders(['X-Locale' => 'ar'])->postJson('/api/v1/auth/login', []),
    ];

    foreach ($responses as $response) {
        expect($response->json('error.message'))->not->toStartWith('api.')
            ->and($response->json('error.message'))->not->toStartWith('error.');
    }
});

// ── The contract that did not move ───────────────────────────────────────────

test('the media rejection reason is still a stable technical identifier', function (): void {
    // `reason` is coarser than the message — three failures share bad_filename —
    // so it is a category a client branches on, not a translation key.
    $source = (string) file_get_contents(app_path('Modules/Media/Services/UploadValidator.php'));

    preg_match_all("/new MediaValidationException\(\s*'([a-z_]+)'/", $source, $matches);

    $reasons = $matches[1];

    expect($reasons)->toHaveCount(9)
        ->and(array_unique($reasons))->toContain('upload_failed', 'empty_file', 'too_large')
        ->and(array_unique($reasons))->toContain('forbidden_extension', 'unsupported_type')
        ->and(array_unique($reasons))->toContain('bad_filename', 'extension_mismatch');
});

test('the Settings casting diagnostics are deliberately not localized', function (): void {
    // Decision (ii): the thirteen generic InvalidArgumentException sites in
    // Settings report a value's type, not something written for a reader, and
    // stay English. The envelope returns a non-key string unchanged, so this
    // is safe rather than silently broken.
    app()->setLocale('ar');

    $message = 'Invalid integer [3] for boolean setting.';

    expect(__($message))->toBe($message);
});

test('every key this scope introduced exists in both dictionaries', function (): void {
    /** @var array<string, string> $en */
    $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true);
    /** @var array<string, string> $ar */
    $ar = json_decode((string) file_get_contents(base_path('lang/ar.json')), true);

    $keys = array_values(array_filter(
        array_keys($en),
        static fn (string $key): bool => (bool) preg_match(
            '/^api\.error\.(auth|authorization|media|settings|integration|notification)\./',
            $key
        )
    ));

    expect($keys)->toHaveCount(29);

    foreach ($keys as $key) {
        expect($ar)->toHaveKey($key)
            ->and($ar[$key])->not->toBe($en[$key], $key.' is untranslated');

        preg_match_all('/:[a-z_]+/', $en[$key], $inEnglish);
        preg_match_all('/:[a-z_]+/', $ar[$key], $inArabic);

        expect(array_diff($inEnglish[0], $inArabic[0]))->toBe([], $key.' dropped a placeholder');
    }
});
