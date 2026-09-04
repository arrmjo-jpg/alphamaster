<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Core\Concerns\HasDisplayLabel;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Localization\Enums\LanguageDirection;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Enums\VerificationStatus;
use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Settings\Enums\SettingType;
use App\Modules\User\Enums\AccountType;
use Illuminate\Support\Facades\Lang;

/**
 * Every enum that carries display labels, so a new one added to the trait
 * without translations fails here rather than in an interface.
 *
 * @return array<int, class-string>
 */
function labelledEnums(): array
{
    return [
        AccountType::class,
        MediaType::class,
        MediaVisibility::class,
        MediaStatus::class,
        ScanStatus::class,
        NotificationType::class,
        NotificationChannel::class,
        MfaType::class,
        UsageStatus::class,
        IntegrationCapability::class,
        SettingType::class,
    ];
}

/**
 * The locales the platform ships translations for.
 *
 * @return array<int, string>
 */
function shippedLocales(): array
{
    return ['en', 'ar'];
}

// ── The key ───────────────────────────────────────────────────────────────────

test('the key is derived from the enum class, not declared', function (): void {
    expect(ScanStatus::NOT_SCANNED->translationKey())
        ->toBe('enum.media.scan_status.not_scanned')
        ->and(MfaType::SMS_OTP->translationKey())
        ->toBe('enum.auth.mfa_type.sms_otp')
        ->and(AccountType::ADMIN->translationKey())
        ->toBe('enum.user.account_type.admin');
});

test('an enum whose name repeats its module keeps the full name', function (): void {
    // NotificationType does not become enum.notification.type: two enums called
    // Type in different modules would then be indistinguishable, and the key
    // would stop being derivable without a special case.
    expect(NotificationType::SECURITY_ALERT->translationKey())
        ->toBe('enum.notification.notification_type.security.alert')
        ->and(NotificationChannel::SMS->translationKey())
        ->toBe('enum.notification.notification_channel.sms')
        ->and(MediaType::IMAGE->translationKey())
        ->toBe('enum.media.media_type.image');
});

test('a value containing a dot produces a key with that dot intact', function (): void {
    // security.alert is the identifier, so the key has five segments. Nothing
    // rewrites or escapes it, and it still resolves.
    app()->setLocale('en');

    expect(NotificationType::SECURITY_ALERT->translationKey())
        ->toContain('security.alert')
        ->and(NotificationType::SECURITY_ALERT->label())
        ->toBe('Security Alert');
});

// ── The label ─────────────────────────────────────────────────────────────────

test('the label follows the active locale while the value does not', function (): void {
    app()->setLocale('en');
    $englishLabel = ScanStatus::NOT_SCANNED->label();
    $englishValue = ScanStatus::NOT_SCANNED->value;

    app()->setLocale('ar');
    $arabicLabel = ScanStatus::NOT_SCANNED->label();
    $arabicValue = ScanStatus::NOT_SCANNED->value;

    expect($englishLabel)->toBe('Not Scanned')
        ->and($arabicLabel)->toBe('لم يُفحص')
        ->and($arabicLabel)->not->toBe($englishLabel)
        ->and($arabicValue)->toBe($englishValue)
        ->and($arabicValue)->toBe('not_scanned');
});

test('no enum reads its locale for itself', function (): void {
    // The trait resolves through the application locale, which SetLocale sets
    // from LocaleResolver. Changing the locale changes every label at once.
    app()->setLocale('ar');

    expect(AccountType::ADMIN->label())->toBe('مدير')
        ->and(MediaType::IMAGE->label())->toBe('صورة')
        ->and(MfaType::SMS_OTP->label())->toBe('رمز عبر رسالة نصية');
});

// ── The fallback ──────────────────────────────────────────────────────────────

test('a missing translation falls back to the technical value', function (): void {
    // ADR 0030: an untranslated label renders the identifier, which is visibly
    // wrong and gets reported, rather than an empty string that looks like a
    // rendering bug or a raw key that looks like nothing at all.
    app()->setLocale('zz');

    expect(ScanStatus::INFECTED->label())->toBe('infected')
        ->and(NotificationType::SECURITY_ALERT->label())->toBe('security.alert');
});

test('the fallback never leaks the translation key', function (): void {
    app()->setLocale('zz');

    foreach (labelledEnums() as $enum) {
        foreach ($enum::cases() as $case) {
            expect($case->label())
                ->not->toStartWith('enum.')
                ->toBe((string) $case->value);
        }
    }
});

// ── The catalogue shape ───────────────────────────────────────────────────────

test('options pairs every case with its label, in the shape ADR 0031 fixes', function (): void {
    app()->setLocale('en');

    $options = MediaVisibility::options();

    expect($options)->toHaveCount(2)
        ->and($options[0])->toBe(['value' => 'public', 'label' => 'Public'])
        ->and($options[1])->toBe(['value' => 'private', 'label' => 'Private']);
});

test('options follows the locale for the label and never for the value', function (): void {
    app()->setLocale('ar');

    $values = array_column(AccountType::options(), 'value');
    $labels = array_column(AccountType::options(), 'label');

    expect($values)->toBe(['admin', 'user'])
        ->and($labels)->toBe(['مدير', 'مستخدم']);
});

// ── Coverage of the shipped translations ──────────────────────────────────────

test('every labelled case is translated in every shipped locale', function (): void {
    $missing = [];

    foreach (shippedLocales() as $locale) {
        app()->setLocale($locale);

        foreach (labelledEnums() as $enum) {
            foreach ($enum::cases() as $case) {
                if (! Lang::has($case->translationKey(), $locale)) {
                    $missing[] = $locale.': '.$case->translationKey();
                }
            }
        }
    }

    expect($missing)->toBe([]);
});

test('no shipped label is left equal to its own identifier', function (): void {
    // A translation copied from the value is indistinguishable from a missing
    // one at a glance, and in Arabic it is simply untranslated.
    $untranslated = [];

    app()->setLocale('ar');

    foreach (labelledEnums() as $enum) {
        foreach ($enum::cases() as $case) {
            if ($case->label() === (string) $case->value) {
                $untranslated[] = $case->translationKey();
            }
        }
    }

    expect($untranslated)->toBe([]);
});

// ── Scope ─────────────────────────────────────────────────────────────────────

test('technical enums deliberately carry no display label', function (): void {
    // TokenAbility is an auth contract, AdminPermission has its own key
    // namespace under ADR 0014, LanguageDirection is a rendering directive
    // rather than text, and VerificationStatus has no consumer. Adding labels
    // to any of them would ship a capability nothing asked for.
    $excluded = [
        TokenAbility::class,
        AdminPermission::class,
        LanguageDirection::class,
        VerificationStatus::class,
    ];

    foreach ($excluded as $enum) {
        expect(in_array(HasDisplayLabel::class, class_uses($enum), true))
            ->toBeFalse("{$enum} should not carry display labels");
    }
});
