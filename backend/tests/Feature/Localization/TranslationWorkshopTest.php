<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Models\Role;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Notification\Models\NotificationTemplate;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config(['queue.default' => 'sync']);

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);
});

// What the platform has to say, in every language it says it in.
//
// The languages screen manages which languages exist; nothing managed what is written
// in them. Adding Arabic did nothing to the roles, the settings copy or the
// notification wording, and no surface anywhere reported that.
//
// Most of what is tested here is the boundary rather than the editing. The workshop
// reaches across three modules' content, so the risk it introduces is not a typo — it
// is an operator writing notification wording through a screen that never asked
// whether they may.

test('the workshop reports the platform’s active languages', function (): void {
    $token = tokenWithPermissions(['settings.view']);

    $response = $this->withToken($token)->getJson('/api/v1/admin/translations')->assertOk();

    $codes = array_column($response->json('data.locales'), 'code');

    expect($codes)->toContain('en')->toContain('ar');
});

test('a localized setting appears with only what has actually been written', function (): void {
    $token = tokenWithPermissions(['settings.view']);

    $response = $this->withToken($token)->getJson('/api/v1/admin/translations')->assertOk();

    $settings = collect($response->json('data.sources'))->firstWhere('key', 'settings');

    expect($settings)->not->toBeNull();

    $entry = collect($settings['entries'])->firstWhere('id', 'general.site_name');

    expect($entry)->not->toBeNull()
        // Nothing has been translated yet, and the column is empty rather than
        // carrying the English value: a fallback here would make an untranslated
        // setting look finished, which is the confusion the workshop exists to remove.
        ->and($entry['fields'][0]['values'])->toBe([]);
});

test('a setting with no per-locale value of its own is not offered for translation', function (): void {
    $token = tokenWithPermissions(['settings.view']);

    $response = $this->withToken($token)->getJson('/api/v1/admin/translations')->assertOk();

    $settings = collect($response->json('data.sources'))->firstWhere('key', 'settings');
    $ids = array_column($settings['entries'], 'id');

    // A retention period is a number, not language. Offering a translation of it would
    // invite somebody to keep records for a different number of days in Arabic.
    expect($ids)->not->toContain('operations.audit_retention_days');
});

test('writing a translation lands in the named language and nowhere else', function (): void {
    $token = tokenWithPermissions(['settings.view', 'settings.update']);

    $this->withToken($token)->putJson('/api/v1/admin/translations/settings/general.site_name', [
        'locale' => 'ar',
        'values' => ['value' => 'ألفاماستر'],
    ])->assertOk();

    /** @var Setting $setting */
    $setting = Setting::query()->where('group', 'general')->where('key', 'site_name')->sole();

    expect($setting->translate('value', 'ar'))->toBe('ألفاماستر')
        // The base column is untouched. Writing there would silently change what every
        // other language falls back to.
        ->and($setting->getRawOriginal('value'))->not->toBe('ألفاماستر');
});

test('a translation written from an English console does not land in English', function (): void {
    $token = tokenWithPermissions(['settings.view', 'settings.update']);

    // The header says the console is in English. The payload says the text is Arabic,
    // and the payload is what decides — otherwise the whole activity of translating
    // from one language into another would be impossible.
    $this->withToken($token)
        ->withHeader('Accept-Language', 'en')
        ->putJson('/api/v1/admin/translations/settings/general.site_name', [
            'locale' => 'ar',
            'values' => ['value' => 'ألفاماستر'],
        ])->assertOk();

    /** @var Setting $setting */
    $setting = Setting::query()->where('group', 'general')->where('key', 'site_name')->sole();

    $written = $setting->translations->pluck('value', 'locale')->all();

    expect($written)->toHaveKey('ar')->not->toHaveKey('en');
});

test('a translated setting is what the platform then reads in that language', function (): void {
    $token = tokenWithPermissions(['settings.view', 'settings.update']);

    $this->withToken($token)->putJson('/api/v1/admin/translations/settings/general.site_name', [
        'locale' => 'ar',
        'values' => ['value' => 'ألفاماستر'],
    ])->assertOk();

    app()->setLocale('ar');
    Cache::flush();

    // Not merely stored: the value the rest of the platform gets for that locale.
    expect(setting('general.site_name'))->toBe('ألفاماستر');
});

test('clearing a translation returns the language to the platform’s own wording', function (): void {
    $token = tokenWithPermissions(['settings.view', 'settings.update']);

    $write = fn (?string $value) => $this->withToken($token)
        ->putJson('/api/v1/admin/translations/settings/general.site_name', [
            'locale' => 'ar',
            'values' => ['value' => $value],
        ]);

    $write('ألفاماستر')->assertOk();

    // Null, not an empty string — and a client that sends one gets the other, because
    // `ConvertEmptyStringsToNull` runs on every request this platform serves. Taking a
    // translation back has to be expressible, or an editor's only way out of a mistake
    // is a different mistake.
    $write(null)->assertOk();

    /** @var Setting $setting */
    $setting = Setting::query()->where('group', 'general')->where('key', 'site_name')->sole();

    expect($setting->translate('value', 'ar'))->not->toBe('ألفاماستر');
});

test('role labels can be translated and become the label that is read', function (): void {
    $token = tokenWithPermissions(['roles.view', 'roles.update']);

    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();

    $this->withToken($token)->putJson('/api/v1/admin/translations/roles/'.$role->getKey(), [
        'locale' => 'ar',
        'values' => ['label' => 'محرّر'],
    ])->assertOk();

    app()->setLocale('ar');

    expect($role->refresh()->displayLabel())->toBe('محرّر');
});

test('a notification template keeps the field an editor did not touch', function (): void {
    $token = tokenWithPermissions(['notifications.view', 'notifications.update']);

    /** @var NotificationTemplate $template */
    $template = NotificationTemplate::query()->firstOrFail();

    $this->withToken($token)->putJson('/api/v1/admin/translations/notification-templates/'.$template->getKey(), [
        'locale' => 'ar',
        'values' => ['subject' => 'موضوع', 'body' => 'نص'],
    ])->assertOk();

    $this->withToken($token)->putJson('/api/v1/admin/translations/notification-templates/'.$template->getKey(), [
        'locale' => 'ar',
        'values' => ['subject' => 'موضوع مصحَّح'],
    ])->assertOk();

    $template->refresh();

    expect($template->translate('subject', 'ar'))->toBe('موضوع مصحَّح')
        // Correcting a subject must not erase the body somebody wrote beside it.
        ->and($template->translate('body', 'ar'))->toBe('نص');
});

// ── The boundary ─────────────────────────────────────────────────────────────

test('an operator is shown only the content they may read', function (): void {
    $token = tokenWithPermissions(['settings.view']);

    $response = $this->withToken($token)->getJson('/api/v1/admin/translations')->assertOk();

    $keys = array_column($response->json('data.sources'), 'key');

    // Present, absent — and absent rather than empty: a heading over nothing tells a
    // restricted operator exactly what they are missing.
    expect($keys)->toContain('settings')
        ->not->toContain('notification-templates')
        ->not->toContain('roles');
});

test('reading a kind of content is not permission to rewrite it', function (): void {
    $token = tokenWithPermissions(['notifications.view']);

    /** @var NotificationTemplate $template */
    $template = NotificationTemplate::query()->firstOrFail();

    $response = $this->withToken($token)->getJson('/api/v1/admin/translations')->assertOk();
    $source = collect($response->json('data.sources'))->firstWhere('key', 'notification-templates');

    expect($source['may_write'])->toBeFalse();

    $this->withToken($token)
        ->putJson('/api/v1/admin/translations/notification-templates/'.$template->getKey(), [
            'locale' => 'ar',
            'values' => ['subject' => 'موضوع'],
        ])->assertForbidden();
});

test('the workshop is not a way around the permission the owning screen enforces', function (): void {
    // Every permission the workshop itself might be thought to need, and none of the
    // ones the content needs. Without the per-source check this would succeed.
    $token = tokenWithPermissions(['settings.view', 'settings.update']);

    /** @var NotificationTemplate $template */
    $template = NotificationTemplate::query()->firstOrFail();

    $this->withToken($token)
        ->putJson('/api/v1/admin/translations/notification-templates/'.$template->getKey(), [
            'locale' => 'ar',
            'values' => ['subject' => 'موضوع'],
        ])
        // Not 403: telling somebody who may not read this content that the item exists
        // is a statement about content they were not granted.
        ->assertNotFound();

    expect($template->refresh()->translate('subject', 'ar'))->not->toBe('موضوع');
});

test('an unknown item is refused rather than quietly discarded', function (): void {
    $token = tokenWithPermissions(['settings.view', 'settings.update']);

    $this->withToken($token)->putJson('/api/v1/admin/translations/settings/general.no_such_setting', [
        'locale' => 'ar',
        'values' => ['value' => 'شيء'],
    ])->assertNotFound()->assertJsonPath('error.code', 'UNKNOWN_TRANSLATION_TARGET');
});

test('a language the platform does not serve is refused', function (): void {
    $token = tokenWithPermissions(['settings.view', 'settings.update']);

    $this->withToken($token)->putJson('/api/v1/admin/translations/settings/general.site_name', [
        'locale' => 'fr',
        'values' => ['value' => 'Quelque chose'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'UNKNOWN_LOCALE');
});

test('the workshop is behind the administrative perimeter', function (): void {
    $this->getJson('/api/v1/admin/translations')->assertUnauthorized();
    $this->putJson('/api/v1/admin/translations/settings/general.site_name', [
        'locale' => 'ar',
        'values' => ['value' => 'شيء'],
    ])->assertUnauthorized();
});

test('completeness counts fields rather than items', function (): void {
    $token = tokenWithPermissions(['notifications.view', 'notifications.update']);

    /** @var NotificationTemplate $template */
    $template = NotificationTemplate::query()->firstOrFail();

    $translated = function () use ($token): int {
        $response = $this->withToken($token)->getJson('/api/v1/admin/translations')->assertOk();
        $source = collect($response->json('data.sources'))->firstWhere('key', 'notification-templates');

        return (int) $source['completeness']['ar']['translated'];
    };

    $write = fn (array $values) => $this->withToken($token)
        ->putJson('/api/v1/admin/translations/notification-templates/'.$template->getKey(), [
            'locale' => 'ar',
            'values' => $values,
        ]);

    $before = $translated();

    // The platform ships translated templates, so the claim under test is about the
    // arithmetic rather than about the seeder: take one template's Arabic away, then
    // write it back.
    $write(['subject' => null, 'body' => null])->assertOk();

    expect($translated())->toBe($before - 2);

    $write(['subject' => 'موضوع', 'body' => 'نص'])->assertOk();

    expect($translated())->toBe($before);

    $response = $this->withToken($token)->getJson('/api/v1/admin/translations')->assertOk();
    $source = collect($response->json('data.sources'))->firstWhere('key', 'notification-templates');

    // Fields, not items: a template counts twice, because a subject in one language
    // over a body in another is not half a translated template in any sense a
    // recipient would recognise.
    expect($source['completeness']['ar']['total'])->toBe(count($source['entries']) * 2);
});

test('a language is taken back whole, or not at all', function (): void {
    $token = tokenWithPermissions(['notifications.view', 'notifications.update']);

    /** @var NotificationTemplate $template */
    $template = NotificationTemplate::query()->firstOrFail();

    $write = fn (array $values) => $this->withToken($token)
        ->putJson('/api/v1/admin/translations/notification-templates/'.$template->getKey(), [
            'locale' => 'ar',
            'values' => $values,
        ]);

    // A subject with no body is a message that cannot be sent. Refused rather than
    // stored, and refused before the database has to say so.
    $write(['body' => null])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'TRANSLATION_REFUSED');

    expect($template->refresh()->translate('body', 'ar'))->not->toBeNull();

    // Both together is the way out, and it removes the row rather than emptying it.
    $write(['subject' => null, 'body' => null])->assertOk();

    expect($template->refresh()->translations->firstWhere('locale', 'ar'))->toBeNull();
});

test('clearing a role label returns it to the platform’s own wording', function (): void {
    $token = tokenWithPermissions(['roles.view', 'roles.update']);

    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();

    $write = fn (?string $label) => $this->withToken($token)
        ->putJson('/api/v1/admin/translations/roles/'.$role->getKey(), [
            'locale' => 'ar',
            'values' => ['label' => $label],
        ]);

    $write('محرّر')->assertOk();
    $write(null)->assertOk();

    app()->setLocale('ar');

    expect($role->refresh()->translations->firstWhere('locale', 'ar'))->toBeNull()
        // Not blank: with no row the label comes from the catalogue again.
        ->and($role->displayLabel())->not->toBe('');
});
