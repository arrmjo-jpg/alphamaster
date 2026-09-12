<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Models\Language;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Models\SettingRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * Editing a localized setting in a language the operator names.
 *
 * The content language — which language a value is written in — is not the display
 * language of the console. An operator reading the console in Arabic writes the English
 * site name, and every label around them stays Arabic (ADR 0043, amended). So the
 * content language travels as its own parameter and never as `X-Locale`.
 *
 * Only settings the registry declares `isLocalized` take part. Everything else is one
 * value for the platform and does not move when the content language does.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    AuditRecord::query()->getQuery()->delete();
});

/** A language the platform did not ship with, added the way an operator adds one. */
function addLanguage(string $code, string $name, string $native, bool $active = true): Language
{
    /** @var Language $language */
    $language = Language::query()->create([
        'code' => $code,
        'name' => $name,
        'native_name' => $native,
        'direction' => 'ltr',
        'is_active' => $active,
        'is_default' => false,
        'sort_order' => 50,
    ]);

    Cache::flush();

    return $language;
}

function settingRow(mixed $response, string $key): array
{
    /** @var array<int, array<string, mixed>> $rows */
    $rows = $response->json('data');

    return collect($rows)->firstWhere('key', $key) ?? [];
}

// ── Reading one language ─────────────────────────────────────────────────────

test('a group is read in the content language the caller names', function (): void {
    addLanguage('es', 'Spanish', 'Español');

    /** @var Setting $siteName */
    $siteName = Setting::query()->where('group', 'general')->where('key', 'site_name')->sole();
    $siteName->setLocalizedValue('en', 'AlphaMaster');
    $siteName->setLocalizedValue('es', 'AlfaMaestro');
    $siteName->save();

    Cache::flush();

    $token = adminToken(roles: ['administrator']);

    $spanish = $this->withToken($token)->getJson('/api/v1/admin/settings/general?locale=es')->assertOk();
    $english = $this->withToken($token)->getJson('/api/v1/admin/settings/general?locale=en')->assertOk();

    expect(settingRow($spanish, 'site_name')['value'])->toBe('AlfaMaestro')
        ->and(settingRow($spanish, 'site_name')['locale'])->toBe('es')
        ->and(settingRow($spanish, 'site_name')['translated'])->toBeTrue()
        ->and(settingRow($english, 'site_name')['value'])->toBe('AlphaMaster');
});

test('a language with nothing written is reported as untranslated, fallback and all', function (): void {
    addLanguage('es', 'Spanish', 'Español');

    /** @var Setting $siteName */
    $siteName = Setting::query()->where('group', 'general')->where('key', 'site_name')->sole();
    $siteName->setLocalizedValue('en', 'AlphaMaster');
    $siteName->save();

    Cache::flush();

    $row = settingRow(
        $this->withToken(adminToken(roles: ['administrator']))
            ->getJson('/api/v1/admin/settings/general?locale=es')
            ->assertOk(),
        'site_name'
    );

    // The platform still answers with something readable — but says plainly that this
    // language holds nothing of its own, so an editor shows an empty field rather than
    // another language's words.
    expect($row['translated'])->toBeFalse()
        ->and($row['value'])->not->toBeNull();
});

test('a setting that is not localized reports no language and does not move with one', function (): void {
    addLanguage('es', 'Spanish', 'Español');

    $token = adminToken(roles: ['administrator']);

    $spanish = settingRow($this->withToken($token)->getJson('/api/v1/admin/settings/general?locale=es')->assertOk(), 'maintenance_mode');
    $english = settingRow($this->withToken($token)->getJson('/api/v1/admin/settings/general?locale=en')->assertOk(), 'maintenance_mode');

    expect($spanish['is_localized'])->toBeFalse()
        ->and($spanish['locale'])->toBeNull()
        ->and($spanish['translated'])->toBeNull()
        ->and($spanish['value'])->toBe($english['value']);
});

// ── Writing one language ─────────────────────────────────────────────────────

test('a write lands in the named content language and leaves the others alone', function (): void {
    addLanguage('es', 'Spanish', 'Español');

    /** @var Setting $siteName */
    $siteName = Setting::query()->where('group', 'general')->where('key', 'site_name')->sole();
    $siteName->setLocalizedValue('en', 'AlphaMaster');
    $siteName->save();

    Cache::flush();

    $token = adminToken(roles: ['administrator']);

    $this->withToken($token)
        ->withHeaders(['If-Match' => settingsVersion('general')])
        ->putJson('/api/v1/admin/settings/general', [
            'settings' => ['site_name' => 'AlfaMaestro'],
            'locale' => 'es',
        ])
        ->assertOk();

    /** @var Setting $saved */
    $saved = Setting::query()->where('group', 'general')->where('key', 'site_name')->with('translations')->sole();

    expect($saved->translations->firstWhere('locale', 'es')?->value)->toBe('AlfaMaestro')
        ->and($saved->translations->firstWhere('locale', 'en')?->value)->toBe('AlphaMaster')
        // The base column is what every other language falls back to, and a localized
        // write must not touch it.
        ->and($saved->getRawValue())->not->toBe('AlfaMaestro');
});

test('a content language the platform does not know is refused', function (): void {
    $token = adminToken(roles: ['administrator']);

    $this->withToken($token)->getJson('/api/v1/admin/settings/general?locale=zz')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'UNKNOWN_CONTENT_LOCALE');

    $this->withToken($token)
        ->withHeaders(['If-Match' => settingsVersion('general')])
        ->putJson('/api/v1/admin/settings/general', [
            'settings' => ['site_name' => 'Nope'],
            'locale' => 'zz',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'UNKNOWN_CONTENT_LOCALE');
});

test('a draft language may be written before it is served', function (): void {
    // A language being prepared is translatable before anybody reads it (ADR 0048).
    addLanguage('es', 'Spanish', 'Español', active: false);

    $token = adminToken(roles: ['administrator']);

    $this->withToken($token)
        ->withHeaders(['If-Match' => settingsVersion('general')])
        ->putJson('/api/v1/admin/settings/general', [
            'settings' => ['site_name' => 'AlfaMaestro'],
            'locale' => 'es',
        ])
        ->assertOk();

    /** @var Setting $saved */
    $saved = Setting::query()->where('group', 'general')->where('key', 'site_name')->with('translations')->sole();

    expect($saved->translations->firstWhere('locale', 'es')?->value)->toBe('AlfaMaestro');
});

test('nothing changes for a caller that names no content language', function (): void {
    $token = adminToken(roles: ['administrator']);

    $this->withToken($token)
        ->withHeaders(['If-Match' => settingsVersion('general')])
        ->putJson('/api/v1/admin/settings/general', ['settings' => ['site_name' => 'Written As Before']])
        ->assertOk();

    /** @var Setting $saved */
    $saved = Setting::query()->where('group', 'general')->where('key', 'site_name')->with('translations')->sole();

    // The request's own locale, exactly as before this parameter existed.
    expect($saved->translations->firstWhere('locale', app()->getLocale())?->value)->toBe('Written As Before');
});

// ── The trail says which language ────────────────────────────────────────────

test('the revision records the language actually written, not the one being read', function (): void {
    addLanguage('es', 'Spanish', 'Español');

    /** @var Setting $siteName */
    $siteName = Setting::query()->where('group', 'general')->where('key', 'site_name')->sole();
    $siteName->setLocalizedValue('es', 'Antiguo');
    $siteName->save();

    Cache::flush();

    $token = adminToken(roles: ['administrator']);

    // The console answers in Arabic while Spanish is being written — the case that
    // recorded the wrong language before this was fixed.
    $this->withToken($token)
        ->withHeaders(['If-Match' => settingsVersion('general'), 'X-Locale' => 'ar'])
        ->putJson('/api/v1/admin/settings/general', [
            'settings' => ['site_name' => 'Nuevo'],
            'locale' => 'es',
        ])
        ->assertOk();

    /** @var SettingRevision $revision */
    $revision = SettingRevision::query()->where('setting_id', $siteName->id)->latest('id')->sole();

    expect($revision->locale)->toBe('es')
        // The value it superseded, in that language and not another's.
        ->and($revision->value)->toBe('Antiguo');
});

test('the audit context names the content language of a localized write', function (): void {
    addLanguage('es', 'Spanish', 'Español');

    $token = adminToken(roles: ['administrator']);

    $this->withToken($token)
        ->withHeaders(['If-Match' => settingsVersion('general'), 'X-Locale' => 'ar'])
        ->putJson('/api/v1/admin/settings/general', [
            'settings' => ['site_name' => 'Nuevo', 'maintenance_mode' => true],
            'locale' => 'es',
        ])
        ->assertOk();

    $records = AuditRecord::query()
        ->whereIn('action', [AuditAction::SETTING_UPDATED, AuditAction::SETTING_CLEARED])
        ->get()
        ->keyBy('subject');

    expect($records['general.site_name']->context['locale'])->toBe('es')
        ->and($records['general.site_name']->context['localized'])->toBeTrue()
        // A setting with one value for the platform has no language to record.
        ->and($records['general.maintenance_mode']->context['locale'])->toBeNull();
});

// ── What the change must not have moved ──────────────────────────────────────

test('the version still travels, and a stale one is still refused', function (): void {
    addLanguage('es', 'Spanish', 'Español');

    $token = adminToken(roles: ['administrator']);
    $version = settingsVersion('general');

    $this->withToken($token)
        ->withHeaders(['If-Match' => $version])
        ->putJson('/api/v1/admin/settings/general', [
            'settings' => ['site_name' => 'Uno'],
            'locale' => 'es',
        ])
        ->assertOk();

    // The same version again: a write built on a read that has since moved.
    $this->withToken($token)
        ->withHeaders(['If-Match' => $version])
        ->putJson('/api/v1/admin/settings/general', [
            'settings' => ['site_name' => 'Dos'],
            'locale' => 'es',
        ])
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'SETTING_VERSION_CONFLICT');
});

test('reading a group in another language still needs permission to read settings', function (): void {
    addLanguage('es', 'Spanish', 'Español');

    // An administrator holding nothing: naming a content language is not a way past
    // the permission that guards reading settings at all.
    $this->withToken(tokenWithPermissions([]))
        ->getJson('/api/v1/admin/settings/general?locale=es')
        ->assertForbidden();
});

test('the declared rules still apply to a value written in another language', function (): void {
    addLanguage('es', 'Spanish', 'Español');

    $token = adminToken(roles: ['administrator']);

    // site_name declares max:150; the language it is written in changes nothing.
    $this->withToken($token)
        ->withHeaders(['If-Match' => settingsVersion('general')])
        ->putJson('/api/v1/admin/settings/general', [
            'settings' => ['site_name' => str_repeat('a', 151)],
            'locale' => 'es',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SETTING_VALUE_REJECTED');
});
