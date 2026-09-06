<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Models\SettingTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->service = app(SettingServiceInterface::class);
});

/** The site name setting, which the catalogue declares localized. */
function siteName(): Setting
{
    return Setting::query()->where('group', 'general')->where('key', 'site_name')->firstOrFail();
}

// ── The table follows the established pattern ────────────────────────────────

test('setting_translations follows the owner-translation shape', function (): void {
    expect(Schema::hasTable('setting_translations'))->toBeTrue()
        ->and(Schema::hasColumns('setting_translations', ['id', 'setting_id', 'locale', 'value', 'created_at', 'updated_at']))
        ->toBeTrue();
});

test('a setting holds one value per locale and no more', function (): void {
    $setting = siteName();

    $setting->setLocalizedValue('en', 'First');
    $setting->setLocalizedValue('en', 'Second');

    expect(SettingTranslation::query()->where('setting_id', $setting->id)->where('locale', 'en')->count())->toBe(1)
        ->and($setting->refresh()->getLocalizedRawValue('en'))->toBe('Second');
});

test('deleting a setting removes its translations', function (): void {
    $setting = siteName();
    $setting->setLocalizedValue('en', 'Gone soon');
    $id = $setting->id;

    $setting->delete();

    expect(SettingTranslation::query()->where('setting_id', $id)->count())->toBe(0);
});

// ── Arabic never returns English, and the reverse ────────────────────────────

test('each locale reads its own value', function (): void {
    // The isolation this whole design exists for.
    $setting = siteName();
    $setting->setLocalizedValue('en', 'AlphaMaster');
    $setting->setLocalizedValue('ar', 'ألفا ماستر');

    app()->setLocale('en');
    expect($this->service->get('general.site_name'))->toBe('AlphaMaster');

    app()->setLocale('ar');
    expect($this->service->get('general.site_name'))->toBe('ألفا ماستر');
});

test('the public payload is not shared between locales', function (): void {
    // Without a locale in the key, the first request in any language would populate
    // one entry and every later request would receive that language for a full TTL
    // (ADR 0018).
    $setting = siteName();
    $setting->setLocalizedValue('en', 'English Name');
    $setting->setLocalizedValue('ar', 'الاسم العربي');

    app()->setLocale('en');
    $english = $this->service->getPublicSettings();

    app()->setLocale('ar');
    $arabic = $this->service->getPublicSettings();

    expect($english['general']['site_name'])->toBe('English Name')
        ->and($arabic['general']['site_name'])->toBe('الاسم العربي');
});

test('a group payload is not shared between locales either', function (): void {
    $setting = siteName();
    $setting->setLocalizedValue('en', 'English Name');
    $setting->setLocalizedValue('ar', 'الاسم العربي');

    app()->setLocale('en');
    $english = $this->service->getPublicGroup('general');

    app()->setLocale('ar');
    $arabic = $this->service->getPublicGroup('general');

    expect($english['site_name'])->toBe('English Name')
        ->and($arabic['site_name'])->toBe('الاسم العربي');
});

test('the order the locales are requested in does not decide the answer', function (): void {
    // The same assertion the other way round: a cache poisoned by whoever asked
    // first would pass one direction and fail this one.
    $setting = siteName();
    $setting->setLocalizedValue('en', 'English Name');
    $setting->setLocalizedValue('ar', 'الاسم العربي');

    app()->setLocale('ar');
    $arabic = $this->service->getPublicSettings();

    app()->setLocale('en');
    $english = $this->service->getPublicSettings();

    expect($arabic['general']['site_name'])->toBe('الاسم العربي')
        ->and($english['general']['site_name'])->toBe('English Name');
});

// ── The fallback chain ADR 0015 defines ─────────────────────────────────────

test('a missing locale falls back rather than going blank', function (): void {
    $setting = siteName();
    $setting->setLocalizedValue('en', 'English Only');

    app()->setLocale('ar');

    expect($this->service->get('general.site_name'))->toBe('English Only');
});

test('a setting with no translation at all reads its base value', function (): void {
    // The base column is the last step of the chain, so a setting nobody has
    // translated is still readable.
    app()->setLocale('ar');

    expect($this->service->get('general.site_name'))->toBe('AlphaMaster Enterprise');
});

// ── Writing ─────────────────────────────────────────────────────────────────

test('an update writes the caller locale and leaves the others alone', function (): void {
    $setting = siteName();
    $setting->setLocalizedValue('en', 'English Name');
    $setting->setLocalizedValue('ar', 'الاسم العربي');

    app()->setLocale('ar');
    $this->service->set('general', 'site_name', 'اسم محدث');

    app()->setLocale('en');
    expect($this->service->get('general.site_name'))->toBe('English Name');

    app()->setLocale('ar');
    expect($this->service->get('general.site_name'))->toBe('اسم محدث');
});

test('a localized write never touches the base column', function (): void {
    // Writing to the base instead would silently change what every other locale
    // falls back to.
    $before = Setting::query()->where('key', 'site_name')->value('value');

    app()->setLocale('ar');
    $this->service->set('general', 'site_name', 'اسم عربي');

    expect(Setting::query()->where('key', 'site_name')->value('value'))->toBe($before);
});

test('a write invalidates every locale, not only the caller one', function (): void {
    $setting = siteName();
    $setting->setLocalizedValue('en', 'English Name');
    $setting->setLocalizedValue('ar', 'الاسم العربي');

    // Warm both locales.
    app()->setLocale('en');
    $this->service->getPublicSettings();
    app()->setLocale('ar');
    $this->service->getPublicSettings();

    // Write in Arabic, then read English: a stale entry in the language nobody
    // just touched is the one that would be served next.
    $this->service->set('general', 'site_name', 'اسم جديد');

    app()->setLocale('en');
    $english = $this->service->getPublicSettings();

    expect($english['general']['site_name'])->toBe('English Name');

    app()->setLocale('ar');
    expect($this->service->getPublicSettings()['general']['site_name'])->toBe('اسم جديد');
});

// ── The invariant ───────────────────────────────────────────────────────────

test('a secret can never be localized, at the engine level', function (): void {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The invariant is asserted on the authoritative engine (ADR 0027).');
    }

    // A credential has no language, and a per-locale copy multiplies what has to be
    // protected. Enforced in the database so it survives a query-builder write.
    $refused = false;

    try {
        DB::table('settings')->insert([
            'id' => (string) Str::ulid(),
            'group' => 'security',
            'key' => 'impossible',
            'value' => null,
            'type' => 'string',
            'is_secret' => true,
            'is_public' => false,
            'is_localized' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (Throwable) {
        $refused = true;
    }

    expect($refused)->toBeTrue('a secret was allowed to be localized');
});
