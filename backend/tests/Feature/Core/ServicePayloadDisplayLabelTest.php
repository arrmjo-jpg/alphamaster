<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Notification\Contracts\PreferenceResolverContract;
use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Enums\SettingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->settings = app(SettingServiceInterface::class);
    $this->preferences = app(PreferenceResolverContract::class);
});

/**
 * Every row of the admin settings payload, flattened out of its groups.
 *
 * @return array<int, array<string, mixed>>
 */
function adminSettingRows(mixed $test): array
{
    $rows = [];

    foreach ($test->settings->getAdminAll() as $group) {
        foreach ($group as $row) {
            $rows[] = $row;
        }
    }

    return $rows;
}

// ── The technical values are untouched ───────────────────────────────────────

test('every setting keeps its raw type beside the label', function (): void {
    $values = array_map(static fn (SettingType $case): string => $case->value, SettingType::cases());

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        $rows = adminSettingRows($this);

        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            expect($row['type'])->toBeIn($values, 'a raw type moved in '.$locale)
                ->and($row['type_label'])->not->toBe($row['type']);
        }
    }
});

test('every preference keeps its raw type and channel beside the labels', function (): void {
    $user = makeAccount(['email' => 'prefs-raw@example.test']);

    $types = array_map(static fn (NotificationType $c): string => $c->value, NotificationType::cases());
    $channels = array_map(static fn (NotificationChannel $c): string => $c->value, NotificationChannel::cases());

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($this->preferences->describe($user) as $row) {
            expect($row['type'])->toBeIn($types, 'a raw type moved in '.$locale)
                ->and($row['channel'])->toBeIn($channels, 'a raw channel moved in '.$locale)
                ->and($row['type_label'])->not->toBe($row['type'])
                ->and($row['channel_label'])->not->toBe($row['channel']);
        }
    }
});

// ── The labels ───────────────────────────────────────────────────────────────

test('setting type labels read in English and Arabic', function (): void {
    $expected = [
        'en' => ['string' => 'Text', 'boolean' => 'Yes / No', 'integer' => 'Whole Number'],
        'ar' => ['string' => 'نص', 'boolean' => 'نعم / لا', 'integer' => 'عدد صحيح'],
    ];

    foreach ($expected as $locale => $pairs) {
        app()->setLocale($locale);

        $byType = [];

        foreach (adminSettingRows($this) as $row) {
            $byType[$row['type']] = $row['type_label'];
        }

        foreach ($pairs as $type => $label) {
            expect($byType)->toHaveKey($type)
                ->and($byType[$type])->toBe($label, $type.' in '.$locale);
        }
    }
});

test('preference labels read in English and Arabic', function (): void {
    $user = makeAccount(['email' => 'prefs-labels@example.test']);

    $expected = [
        'en' => ['security.alert' => 'Security Alert', 'mail' => 'Email', 'database' => 'In-App'],
        'ar' => ['security.alert' => 'تنبيه أمني', 'mail' => 'بريد إلكتروني', 'database' => 'داخل التطبيق'],
    ];

    foreach ($expected as $locale => $pairs) {
        app()->setLocale($locale);

        $labels = [];

        foreach ($this->preferences->describe($user) as $row) {
            $labels[$row['type']] = $row['type_label'];
            $labels[$row['channel']] = $row['channel_label'];
        }

        foreach ($pairs as $value => $label) {
            expect($labels[$value] ?? null)->toBe($label, $value.' in '.$locale);
        }
    }
});

test('switching locale changes every label and no value', function (): void {
    $user = makeAccount(['email' => 'prefs-switch@example.test']);

    app()->setLocale('en');
    $englishSettings = adminSettingRows($this);
    $englishPreferences = $this->preferences->describe($user);

    app()->setLocale('ar');
    $arabicSettings = adminSettingRows($this);
    $arabicPreferences = $this->preferences->describe($user);

    foreach ($englishSettings as $i => $row) {
        expect($arabicSettings[$i]['type'])->toBe($row['type'], 'setting type moved')
            ->and($arabicSettings[$i]['type_label'])->not->toBe($row['type_label']);
    }

    foreach ($englishPreferences as $i => $row) {
        expect($arabicPreferences[$i]['type'])->toBe($row['type'], 'preference type moved')
            ->and($arabicPreferences[$i]['channel'])->toBe($row['channel'], 'channel moved')
            ->and($arabicPreferences[$i]['type_label'])->not->toBe($row['type_label'])
            ->and($arabicPreferences[$i]['channel_label'])->not->toBe($row['channel_label']);
    }
});

test('every case of all three enums resolves in both locales', function (): void {
    $checked = 0;

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ([SettingType::class, NotificationType::class, NotificationChannel::class] as $enum) {
            foreach ($enum::cases() as $case) {
                expect($case->label())->not->toBe($case->value, $enum.'::'.$case->value.' in '.$locale);
                $checked++;
            }
        }
    }

    expect($checked)->toBe(22);
});

// ── The shapes ───────────────────────────────────────────────────────────────

test('the admin setting row gains one field and nothing else', function (): void {
    app()->setLocale('en');

    expect(array_keys(adminSettingRows($this)[0]))->toBe([
        'id', 'group', 'key', 'value', 'type', 'type_label',
        'is_secret', 'is_public', 'description', 'updated_at',
    ]);
});

test('the preference row gains two fields and nothing else', function (): void {
    app()->setLocale('en');

    $user = makeAccount(['email' => 'prefs-shape@example.test']);

    expect(array_keys($this->preferences->describe($user)[0]))->toBe([
        'type', 'type_label', 'channel', 'channel_label', 'enabled', 'silenceable',
    ]);
});

test('the public settings payload gains no metadata', function (): void {
    // The public endpoint exposes keys and typed values only. It never carried a
    // type, so there is nothing to label and nothing should appear.
    app()->setLocale('ar');

    $encoded = (string) json_encode($this->settings->getPublicSettings());

    expect($encoded)->not->toContain('type_label')
        ->and($encoded)->not->toContain('is_secret')
        ->and($encoded)->not->toContain('"type"');
});

// ── End to end ───────────────────────────────────────────────────────────────

test('the admin settings endpoint carries type labels in the requested locale', function (): void {
    $token = adminWithRoles($this, ['super_admin'], 'settings-label@example.test')['token'];

    foreach (['en' => 'Text', 'ar' => 'نص'] as $locale => $expected) {
        resetClient($this);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Locale' => $locale])
            ->getJson('/api/v1/admin/settings');

        $response->assertOk();

        $rows = collect($response->json('data'))->flatten(1);
        $strings = $rows->where('type', 'string');

        expect($strings)->not->toBeEmpty()
            ->and($strings->pluck('type_label')->unique()->all())->toBe([$expected]);
    }
});

test('the preferences endpoint carries both labels in the requested locale', function (): void {
    $user = regularWithToken($this, 'prefs-http@example.test');

    foreach (['en' => ['Security Alert', 'Email'], 'ar' => ['تنبيه أمني', 'بريد إلكتروني']] as $locale => $pair) {
        resetClient($this);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$user['token'], 'X-Locale' => $locale])
            ->getJson('/api/v1/notifications/preferences');

        $response->assertOk();

        $row = collect($response->json('data'))
            ->firstWhere(fn (array $r): bool => $r['type'] === 'security.alert' && $r['channel'] === 'mail');

        expect($row)->not->toBeNull()
            ->and($row['type'])->toBe('security.alert')
            ->and($row['channel'])->toBe('mail')
            ->and($row['type_label'])->toBe($pair[0])
            ->and($row['channel_label'])->toBe($pair[1]);
    }
});
