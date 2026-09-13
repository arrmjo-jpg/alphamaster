<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Models\Role;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\TranslationSuggestion;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Notification\Models\NotificationTemplate;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config(['queue.default' => 'sync']);

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);
});

// AI proposes, and a person accepts.
//
// The whole of this file is about that sentence (ADR 0044 §5). A generated translation
// is placed where a translator can read it and is written nowhere until somebody presses
// a button — so the audit trail keeps recording who changed a value, quality stays
// somebody's responsibility, and nothing has to be undone when the model is wrong.
//
// Every vendor call here is faked at the wire, so the real driver runs and no API key
// exists anywhere.

function withAiProvider(string $answer = 'حفظ'): void
{
    IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->update(['is_default' => false]);

    /** @var IntegrationProvider $provider */
    $provider = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->where('driver', 'openai')
        ->firstOrFail();

    $provider->setCredentials(['api_key' => 'sk-not-a-real-key']);
    $provider->forceFill(['is_active' => true, 'is_default' => true])->save();

    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => $answer]]],
        'usage' => ['total_tokens' => 9],
    ], 200)]);
}

/**
 * Give a localized setting an English value, so there is something to translate from.
 *
 * Localized settings ship with no per-locale value at all — the base column holds the
 * default and `setting_translations` is empty — so a workshop over a fresh platform
 * has nothing to propose from until somebody writes the source language.
 */
function settingsCopyInEnglish(): void
{
    /** @var Setting $setting */
    $setting = Setting::query()->where('group', 'general')->where('key', 'site_name')->sole();
    $setting->setLocalizedValue('en', 'AlphaMaster');
    Cache::flush();
}

/**
 * Take one template's Arabic away, so there is a gap to fill.
 *
 * Templates ship translated into both languages, which is the right default and means
 * an untouched platform has nothing outstanding to suggest.
 */
function templateMissingArabic(): NotificationTemplate
{
    /** @var NotificationTemplate $template */
    $template = NotificationTemplate::query()->firstOrFail();
    $template->translations()->where('locale', 'ar')->delete();
    $template->unsetRelation('translations');

    return $template;
}

/** English is what everything is translated from in these tests. */
function englishIsDefault(): void
{
    Language::query()->update(['is_default' => false]);
    Language::query()->where('code', 'en')->update(['is_default' => true, 'is_active' => true]);
    Cache::flush();
}

function suggestFor(mixed $test, string $token, array $body = []): mixed
{
    return $test->withToken($token)->postJson(
        '/api/v1/admin/translations/suggestions',
        array_merge(['locale' => 'ar'], $body)
    );
}

test('a platform with no AI provider says so rather than queueing work that will fail', function (): void {
    englishIsDefault();
    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);

    suggestFor($this, $token)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'AI_NOT_CONFIGURED');

    // Not thirty queued failures that each say the same thing, and not thirty rows an
    // operator has to clear.
    expect(TranslationSuggestion::query()->count())->toBe(0);
});

test('asking generates a suggestion and writes nothing', function (): void {
    englishIsDefault();
    withAiProvider('محرّر');

    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();
    $role->setTranslation('en', ['label' => 'Editor']);

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);

    suggestFor($this, $token, ['source' => 'roles', 'item' => (string) $role->getKey()])->assertOk();

    $suggestion = TranslationSuggestion::query()->sole();

    expect($suggestion->status)->toBe(SuggestionStatus::READY)
        ->and($suggestion->suggestion)->toBe('محرّر')
        ->and($suggestion->source_text)->toBe('Editor')
        // The point of the whole design: the content is untouched until a person acts.
        ->and($role->refresh()->translations->firstWhere('locale', 'ar'))->toBeNull();
});

test('accepting writes through the same path a typed translation takes', function (): void {
    englishIsDefault();
    withAiProvider('محرّر');

    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();
    $role->setTranslation('en', ['label' => 'Editor']);

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);
    suggestFor($this, $token, ['source' => 'roles'])->assertOk();

    $suggestion = TranslationSuggestion::query()->where('item_id', (string) $role->getKey())->sole();

    $this->withToken($token)
        ->postJson('/api/v1/admin/translations/suggestions/'.$suggestion->id.'/accept', ['text' => 'محرّر'])
        ->assertOk()
        ->assertJsonPath('data.edited', false);

    app()->setLocale('ar');

    expect($role->refresh()->displayLabel())->toBe('محرّر')
        ->and($suggestion->refresh()->status)->toBe(SuggestionStatus::ACCEPTED);
});

test('a translator who rewrites the suggestion is recorded as having done so', function (): void {
    englishIsDefault();
    withAiProvider('مُحرِّر');

    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();
    $role->setTranslation('en', ['label' => 'Editor']);

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);
    suggestFor($this, $token, ['source' => 'roles'])->assertOk();

    $suggestion = TranslationSuggestion::query()->where('item_id', (string) $role->getKey())->sole();

    $this->withToken($token)
        ->postJson('/api/v1/admin/translations/suggestions/'.$suggestion->id.'/accept', ['text' => 'محرّر'])
        ->assertOk();

    app()->setLocale('ar');

    // Provenance rather than decoration: "a person wrote this" and "a person let this
    // through" are different facts about the same row.
    expect($suggestion->refresh()->edited)->toBeTrue()
        ->and($role->refresh()->displayLabel())->toBe('محرّر');
});

test('dismissing writes nothing at all', function (): void {
    englishIsDefault();
    settingsCopyInEnglish();
    withAiProvider();

    $token = tokenWithPermissions(['settings.view', 'settings.update', 'ai.use']);
    suggestFor($this, $token, ['source' => 'settings'])->assertOk();

    $suggestion = TranslationSuggestion::query()->where('item_id', 'general.site_name')->sole();

    $this->withToken($token)
        ->deleteJson('/api/v1/admin/translations/suggestions/'.$suggestion->id)
        ->assertOk();

    /** @var Setting $setting */
    $setting = Setting::query()->where('group', 'general')->where('key', 'site_name')->sole();

    expect($suggestion->refresh()->status)->toBe(SuggestionStatus::DISMISSED)
        ->and($setting->translations->firstWhere('locale', 'ar'))->toBeNull();
});

// ── Never silently overwritten ────────────────────────────────────────────────

test('a field somebody has already translated is left alone by default', function (): void {
    englishIsDefault();
    withAiProvider();

    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();
    $role->setTranslation('en', ['label' => 'Editor']);
    $role->setTranslation('ar', ['label' => 'مكتوب بيد إنسان']);

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);

    $response = suggestFor($this, $token, ['source' => 'roles', 'item' => (string) $role->getKey()])->assertOk();

    // Asking for a language means filling the gaps. Paying a vendor to replace text a
    // person already wrote is a request an operator makes on purpose.
    expect($response->json('data.queued'))->toBe(0)
        ->and($response->json('data.skipped'))->toBeGreaterThan(0)
        ->and(TranslationSuggestion::query()->count())->toBe(0);
});

test('a translated field can be re-suggested when the operator asks for it', function (): void {
    englishIsDefault();
    withAiProvider('اقتراح جديد');

    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();
    $role->setTranslation('en', ['label' => 'Editor']);
    $role->setTranslation('ar', ['label' => 'مكتوب بيد إنسان']);

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);

    suggestFor($this, $token, [
        'source' => 'roles',
        'item' => (string) $role->getKey(),
        'include_translated' => true,
    ])->assertOk();

    $suggestion = TranslationSuggestion::query()->sole();

    // Still only a proposal, and the existing text is still what the platform reads.
    expect($suggestion->suggestion)->toBe('اقتراح جديد')
        ->and($suggestion->existing_text)->toBe('مكتوب بيد إنسان')
        ->and($role->refresh()->translate('label', 'ar'))->toBe('مكتوب بيد إنسان');
});

test('a suggestion cannot be applied over a translation written while it waited', function (): void {
    englishIsDefault();
    withAiProvider('اقتراح');

    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();
    $role->setTranslation('en', ['label' => 'Editor']);

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);
    suggestFor($this, $token, ['source' => 'roles', 'item' => (string) $role->getKey()])->assertOk();

    $suggestion = TranslationSuggestion::query()->sole();

    // Somebody translated it by hand while the suggestion was queued.
    $role->setTranslation('ar', ['label' => 'كتبها إنسان أثناء الانتظار']);

    $this->withToken($token)
        ->postJson('/api/v1/admin/translations/suggestions/'.$suggestion->id.'/accept', ['text' => 'اقتراح'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRANSLATION_MOVED');

    expect($role->refresh()->translate('label', 'ar'))->toBe('كتبها إنسان أثناء الانتظار');
});

test('pressing the button twice does not cost twice', function (): void {
    englishIsDefault();
    withAiProvider();

    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();
    $role->setTranslation('en', ['label' => 'Editor']);

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);

    suggestFor($this, $token, ['source' => 'roles', 'item' => (string) $role->getKey()])->assertOk();
    $second = suggestFor($this, $token, ['source' => 'roles', 'item' => (string) $role->getKey()])->assertOk();

    expect($second->json('data.queued'))->toBe(0)
        ->and(TranslationSuggestion::query()->count())->toBe(1);
});

// ── The boundary ─────────────────────────────────────────────────────────────

test('asking needs the permission that governs spending', function (): void {
    englishIsDefault();
    withAiProvider();

    // Everything needed to write the content, and not the permission to spend on it.
    $token = tokenWithPermissions(['roles.view', 'roles.update']);

    suggestFor($this, $token, ['source' => 'roles'])->assertForbidden();

    expect(TranslationSuggestion::query()->count())->toBe(0);
});

test('nothing is proposed for content the requester may not write', function (): void {
    englishIsDefault();
    settingsCopyInEnglish();
    templateMissingArabic();
    withAiProvider();

    // May spend, may write settings copy, may not touch notification wording. The
    // template has a gap on purpose: without one there would be nothing to propose for
    // it either way, and the test would pass for the wrong reason.
    $token = tokenWithPermissions(['settings.view', 'settings.update', 'ai.use']);

    suggestFor($this, $token)->assertOk();

    $sources = TranslationSuggestion::query()->pluck('source_key')->unique()->all();

    // Proposing for content somebody cannot write would spend money on something they
    // cannot use — and hand them the source text of content they were not granted.
    expect($sources)->toContain('settings')
        ->not->toContain('notification-templates')
        ->not->toContain('roles');
});

test('a suggestion is only readable by somebody who may write its content', function (): void {
    englishIsDefault();
    templateMissingArabic();
    withAiProvider();

    $writer = tokenWithPermissions(['notifications.view', 'notifications.update', 'ai.use']);
    suggestFor($this, $writer, ['source' => 'notification-templates'])->assertOk();

    expect(TranslationSuggestion::query()->count())->toBeGreaterThan(0);

    resetClient($this);
    $other = tokenWithPermissions(['settings.view', 'settings.update']);

    $visible = $this->withToken($other)
        ->getJson('/api/v1/admin/translations/suggestions?locale=ar')
        ->assertOk()
        ->json('data');

    // The suggestion carries the source text of the content it proposes for.
    expect($visible)->toBe([]);
});

test('accepting needs the content’s own write permission', function (): void {
    englishIsDefault();
    templateMissingArabic();
    withAiProvider();

    $writer = tokenWithPermissions(['notifications.view', 'notifications.update', 'ai.use']);
    suggestFor($this, $writer, ['source' => 'notification-templates'])->assertOk();

    $suggestion = TranslationSuggestion::query()->firstOrFail();

    resetClient($this);
    $other = tokenWithPermissions(['settings.view', 'settings.update', 'ai.use']);

    $this->withToken($other)
        ->postJson('/api/v1/admin/translations/suggestions/'.$suggestion->id.'/accept', ['text' => 'x'])
        ->assertForbidden();

    expect($suggestion->refresh()->status)->toBe(SuggestionStatus::READY);
});

test('the suggestion routes are behind the administrative perimeter', function (): void {
    $this->getJson('/api/v1/admin/translations/suggestions?locale=ar')->assertUnauthorized();
    $this->postJson('/api/v1/admin/translations/suggestions', ['locale' => 'ar'])->assertUnauthorized();
});

// ── When the vendor does not answer ───────────────────────────────────────────

test('a failed generation is recorded with its reason rather than left pending', function (): void {
    englishIsDefault();

    IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->update(['is_default' => false]);

    /** @var IntegrationProvider $provider */
    $provider = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->where('driver', 'openai')
        ->firstOrFail();
    $provider->setCredentials(['api_key' => 'sk-not-a-real-key']);
    $provider->forceFill(['is_active' => true, 'is_default' => true])->save();

    Http::fake(['api.openai.com/*' => Http::response([
        'error' => ['code' => 'model_not_found', 'message' => 'No such model.'],
    ], 404)]);

    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();
    $role->setTranslation('en', ['label' => 'Editor']);

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);
    suggestFor($this, $token, ['source' => 'roles', 'item' => (string) $role->getKey()])->assertOk();

    $suggestion = TranslationSuggestion::query()->sole();

    // A translator who asked for thirty and got twenty-eight needs to know which two
    // did not arrive and why. A spinner that never resolves reads as the platform
    // being broken rather than as one vendor call having failed.
    expect($suggestion->status)->toBe(SuggestionStatus::FAILED)
        ->and($suggestion->error_code)->toBe('model_not_found')
        ->and($suggestion->error_message)->toBe('No such model.');
});

test('a failed suggestion is shown rather than quietly omitted', function (): void {
    englishIsDefault();

    TranslationSuggestion::query()->create([
        'source_key' => 'roles',
        'item_id' => '1',
        'field' => 'label',
        'locale' => 'ar',
        'status' => SuggestionStatus::FAILED,
        'source_text' => 'Editor',
        'error_code' => 'model_not_found',
        'error_message' => 'No such model.',
    ]);

    $token = tokenWithPermissions(['roles.view', 'roles.update']);

    $listed = $this->withToken($token)
        ->getJson('/api/v1/admin/translations/suggestions?locale=ar')
        ->assertOk()
        ->json('data');

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['status'])->toBe('failed')
        ->and($listed[0]['error_message'])->toBe('No such model.');
});
