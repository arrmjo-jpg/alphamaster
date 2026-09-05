<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\MfaType;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\User\Enums\AccountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
});

/**
 * The MFA status payload for a signed-in administrator.
 *
 * @return array<string, mixed>
 */
function adminStatus(mixed $test, string $locale = 'en', string $email = 'options-admin@example.com'): array
{
    resetClient($test);

    makeAccount(['email' => $email, 'account_type' => AccountType::ADMIN]);
    $token = signInAdminWithMfa($test, $email, TEST_ACCOUNT_PASSWORD)['token'];

    resetClient($test);

    return $test->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Locale' => $locale])
        ->getJson('/api/v1/auth/mfa')
        ->assertOk()
        ->json('data');
}

/**
 * The MFA status payload for a signed-in regular account.
 *
 * @return array<string, mixed>
 */
function regularStatus(mixed $test, string $locale = 'en', string $email = 'options-user@example.com'): array
{
    resetClient($test);

    $token = regularWithToken($test, $email)['token'];

    resetClient($test);

    return $test->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Locale' => $locale])
        ->getJson('/api/v1/auth/mfa')
        ->assertOk()
        ->json('data');
}

/**
 * A catalogue must mirror the array it accompanies, in order.
 */
function assertMirrors(array $source, array $catalogue, string $what): void
{
    expect(array_column($catalogue, 'value'))->toBe($source, $what.' values diverged from its source array')
        ->and($catalogue)->toHaveCount(count($source), $what.' has a different length');

    foreach ($catalogue as $entry) {
        expect(array_keys($entry))->toBe(['value', 'label'], $what.' entry is not {value, label}');
    }
}

// ── The existing fields are untouched ────────────────────────────────────────

test('a regular account still sees both methods available, unchanged', function (): void {
    $data = regularStatus($this);

    expect($data['available_methods'])->toBe([MfaType::TOTP->value, MfaType::SMS_OTP->value])
        ->and($data['methods'])->toBe([]);
});

test('an administrator still sees only the policy-satisfying method, unchanged', function (): void {
    $data = adminStatus($this);

    expect($data['available_methods'])->toBe([MfaType::TOTP->value])
        ->and($data['satisfies_policy'])->toBeTrue();
});

test('the payload gains two fields and nothing else', function (): void {
    expect(array_keys(regularStatus($this)))->toBe([
        'enabled',
        'satisfies_policy',
        'methods',
        'methods_options',
        'available_methods',
        'available_methods_options',
        'recovery_codes_remaining',
    ]);
});

// ── Each catalogue mirrors its own source ────────────────────────────────────

test('each catalogue corresponds exactly to the array it accompanies', function (): void {
    foreach ([regularStatus($this), adminStatus($this)] as $data) {
        assertMirrors($data['methods'], $data['methods_options'], 'methods_options');
        assertMirrors($data['available_methods'], $data['available_methods_options'], 'available_methods_options');
    }
});

test('an empty methods array produces an empty catalogue', function (): void {
    // A regular account that has enrolled nothing: the catalogue is empty rather
    // than listing every case the enum happens to define.
    $data = regularStatus($this);

    expect($data['methods'])->toBe([])
        ->and($data['methods_options'])->toBe([])
        ->and($data['available_methods_options'])->not->toBeEmpty();
});

test('a confirmed method appears in the catalogue with its label', function (): void {
    // Enrolment is mandatory for administrators, so a signed-in administrator
    // already holds a confirmed TOTP method.
    $data = adminStatus($this, 'en', 'confirmed@example.com');

    expect($data['methods'])->toBe([MfaType::TOTP->value])
        ->and($data['methods_options'])->toBe([
            ['value' => 'totp', 'label' => 'Authenticator App'],
        ]);

    assertMirrors($data['methods'], $data['methods_options'], 'methods_options');
});

test('the same confirmed method is labelled in Arabic', function (): void {
    $data = adminStatus($this, 'ar', 'confirmed-ar@example.com');

    expect($data['methods'])->toBe([MfaType::TOTP->value])
        ->and($data['methods_options'])->toBe([
            ['value' => 'totp', 'label' => 'تطبيق المصادقة'],
        ]);
});

// ── Ordering ─────────────────────────────────────────────────────────────────

test('the catalogue keeps the declaration order of the source array', function (): void {
    $data = regularStatus($this);

    // MfaType declares TOTP before SMS_OTP and the filters preserve that, so the
    // catalogue must not re-sort — by value, by label, or otherwise.
    expect($data['available_methods'])->toBe(['totp', 'sms_otp'])
        ->and(array_column($data['available_methods_options'], 'value'))->toBe(['totp', 'sms_otp']);
});

// ── Labels ───────────────────────────────────────────────────────────────────

test('English labels are correct', function (): void {
    $data = regularStatus($this, 'en');

    expect($data['available_methods_options'])->toBe([
        ['value' => 'totp', 'label' => 'Authenticator App'],
        ['value' => 'sms_otp', 'label' => 'SMS Code'],
    ]);
});

test('Arabic labels are correct', function (): void {
    $data = regularStatus($this, 'ar');

    expect($data['available_methods_options'])->toBe([
        ['value' => 'totp', 'label' => 'تطبيق المصادقة'],
        ['value' => 'sms_otp', 'label' => 'رمز عبر رسالة نصية'],
    ]);
});

test('the locale changes every label and no value', function (): void {
    $english = regularStatus($this, 'en', 'switch-en@example.com');
    $arabic = regularStatus($this, 'ar', 'switch-ar@example.com');

    expect($arabic['available_methods'])->toBe($english['available_methods'])
        ->and(array_column($arabic['available_methods_options'], 'value'))
        ->toBe(array_column($english['available_methods_options'], 'value'));

    foreach ($arabic['available_methods_options'] as $i => $entry) {
        expect($entry['label'])->not->toBe(
            $english['available_methods_options'][$i]['label'],
            $entry['value'].' label did not follow the locale'
        );
    }
});

// ── The policy ───────────────────────────────────────────────────────────────

test('an administrator is never offered sms_otp, in either field or either locale', function (): void {
    // ADR 0013: SMS does not satisfy the administrator requirement. The catalogue
    // is derived from the filtered array precisely so it cannot reintroduce it.
    foreach (['en', 'ar'] as $locale) {
        $data = adminStatus($this, $locale, 'policy-'.$locale.'@example.com');

        expect($data['available_methods'])->not->toContain('sms_otp')
            ->and(array_column($data['available_methods_options'], 'value'))->not->toContain('sms_otp')
            ->and(array_column($data['methods_options'], 'value'))->not->toContain('sms_otp')
            ->and($data['available_methods_options'])->toHaveCount(1);
    }
});

test('the enum defines a case the administrator payload deliberately omits', function (): void {
    // Proves the previous test is not vacuous: sms_otp exists and is offered to
    // someone, just never to an administrator.
    expect(MfaType::values())->toContain('sms_otp')
        ->and(regularStatus($this)['available_methods'])->toContain('sms_otp');
});

test('no label ever falls back to its identifier', function (): void {
    // A missing key makes label() return the backed value (ADR 0030: visibly
    // wrong rather than blank). Comparing English to Arabic cannot catch that on
    // its own — `SMS Code` and `sms_otp` differ too — so the fallback is asserted
    // against directly, in both locales and both catalogues.
    foreach (['en', 'ar'] as $locale) {
        $data = regularStatus($this, $locale, 'fallback-'.$locale.'@example.com');

        $entries = array_merge($data['methods_options'], $data['available_methods_options']);

        expect($entries)->not->toBeEmpty();

        foreach ($entries as $entry) {
            expect($entry['label'])->not->toBe(
                $entry['value'],
                $entry['value'].' rendered its identifier in '.$locale
            );
        }
    }
});
