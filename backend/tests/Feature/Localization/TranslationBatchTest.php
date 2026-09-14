<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Models\Role;
use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Core\Translation\FieldGroup;
use App\Modules\Core\Translation\FieldType;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Enums\BatchStatus;
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Jobs\GenerateTranslationSuggestion;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\TranslationBatch;
use App\Modules\Localization\Models\TranslationSuggestion;
use App\Modules\Localization\Services\HtmlFidelity;
use App\Modules\Localization\Services\TranslationPrompt;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Notification\Models\NotificationTemplate;
use App\Modules\Pages\Enums\PagePermission;
use App\Modules\Pages\Enums\PageStatus;
use App\Modules\Pages\Models\Page;
use App\Modules\Pages\Models\PageTranslation;
use App\Modules\Pages\Services\PageService;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use App\Modules\Team\Enums\TeamPermission;
use App\Modules\Team\Models\TeamMember;
use App\Modules\Team\Models\TeamMemberTranslation;
use App\Modules\Team\Services\TeamService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

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

// An item is translated, reviewed and accepted as one (ADR 0056).
//
// AI proposes and a person accepts (ADR 0044 §5) — once per item, never per field. The fields
// that are sent are read from the item's metadata; the operator is never asked about a field,
// and there is no endpoint that would let them.
//
// Every vendor call is faked at the wire, so the real driver runs and no API key exists
// anywhere.

function openAiConfigured(): void
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
}

/**
 * A provider that answers every field — with one text, or with what a closure makes of the
 * text it was sent.
 *
 * @param  string|Closure(string): string  $answer
 */
function aiAnswering(string|Closure $answer): void
{
    openAiConfigured();

    Http::fake(['api.openai.com/*' => function (HttpRequest $request) use ($answer) {
        $text = is_string($answer) ? $answer : $answer(sentContent($request));

        return Http::response([
            'choices' => [['message' => ['content' => $text]]],
            'usage' => ['total_tokens' => 9],
        ], 200);
    }]);
}

/** What one request asked the provider to translate. */
function sentContent(HttpRequest $request): string
{
    $messages = $request->data()['messages'] ?? [];

    return (string) (end($messages)['content'] ?? '');
}

/** What one request told the provider about the task. */
function sentInstruction(HttpRequest $request): string
{
    return (string) ($request->data()['messages'][0]['content'] ?? '');
}

/**
 * Every request the provider was sent.
 *
 * @return list<HttpRequest>
 */
function sentToProvider(): array
{
    return Http::recorded()->map(fn (array $pair): HttpRequest => $pair[0])->values()->all();
}

/** English is what everything is translated from in these tests. */
function englishAsSource(): void
{
    Language::query()->update(['is_default' => false]);
    Language::query()->where('code', 'en')->update(['is_default' => true, 'is_active' => true]);
    Cache::flush();
}

/**
 * Localized settings ship with no per-locale value at all, so there is nothing to translate
 * from until somebody writes the source language.
 */
function siteNameInEnglish(string $value = 'AlphaMaster'): void
{
    /** @var Setting $setting */
    $setting = Setting::query()->where('group', 'general')->where('key', 'site_name')->sole();
    $setting->setLocalizedValue('en', $value);
    Cache::flush();
}

/** Templates ship translated, so a gap has to be made. */
function templateWithoutLocale(string $locale): NotificationTemplate
{
    /** @var NotificationTemplate $template */
    $template = NotificationTemplate::query()->orderBy('type')->firstOrFail();
    $template->translations()->where('locale', $locale)->delete();
    $template->unsetRelation('translations');

    return $template;
}

function languageAdded(string $code, string $name, string $nativeName): Language
{
    $language = Language::query()->create([
        'code' => $code,
        'name' => $name,
        'native_name' => $nativeName,
        'direction' => 'ltr',
        'is_active' => false,
        'is_default' => false,
        'sort_order' => 9,
    ]);

    Cache::flush();

    return $language;
}

/**
 * @param  array<string, string>  $values
 * @param  array<string, string>|null  $seo
 */
function englishPage(array $values, ?array $seo = null): Page
{
    $service = app(PageService::class);
    $page = $service->create(0, null);
    $service->writeTranslation($page, 'en', $values, $seo, null);

    return $page->refresh();
}

function englishTeamMember(string $name, string $position): TeamMember
{
    $service = app(TeamService::class);
    $member = $service->create([], null);
    $service->writeTranslation($member, 'en', ['name' => $name, 'position' => $position], null, null);

    return $member->refresh();
}

function editorInEnglish(string $label = 'Editor'): Role
{
    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();
    $role->setTranslation('en', ['label' => $label]);

    return $role->refresh();
}

/**
 * @param  array<string, mixed>  $body
 */
function translateWithAi(mixed $test, string $token, array $body = []): mixed
{
    return $test->withToken($token)->postJson('/api/v1/admin/translations/batches', array_merge(['locale' => 'ar'], $body));
}

/**
 * @param  array<string, string|null>  $values
 */
function acceptTranslation(mixed $test, string $token, TranslationBatch $batch, array $values = []): mixed
{
    return $test->withToken($token)->postJson(
        '/api/v1/admin/translations/batches/'.$batch->getKey().'/accept',
        $values === [] ? [] : ['values' => $values]
    );
}

// ── A new language is a whole language ───────────────────────────────────────

test('a language added in Language Management is in the workshop at once, with every item not translated', function (): void {
    englishAsSource();
    $token = tokenWithPermissions(['settings.view', 'roles.view', 'notifications.view', PagePermission::VIEW->value, TeamPermission::VIEW->value]);

    englishPage(['title' => 'About us', 'body' => '<p>Who we are.</p>']);
    englishTeamMember('Nadia Haddad', 'Editor');

    // Add Language → Save. Nothing else.
    $this->withToken($token)->postJson('/api/v1/admin/languages', [
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
        'direction' => 'ltr',
        'is_active' => false,
    ])->assertCreated();

    $response = $this->withToken($token)->getJson('/api/v1/admin/translations?target=fr&per_page=100')->assertOk();

    $entries = collect($response->json('data.entries'));
    $sources = collect($response->json('data.sources'))->keyBy('key');

    expect(array_column($response->json('data.locales'), 'code'))->toContain('fr')
        ->and($entries->pluck('status')->unique()->values()->all())->toBe(['not_translated'])
        ->and($entries->pluck('source')->unique()->sort()->values()->all())
        ->toBe(['notification-templates', 'pages', 'roles', 'settings', 'team'])
        ->and($sources['pages']['statuses']['not_translated'])->toBe(1)
        ->and($sources['team']['statuses']['not_translated'])->toBe(1)
        ->and($response->json('data.coverage.translated'))->toBe(0);

    // Not translated is the absence of a row, and no row was made to say so.
    foreach (['setting_translations', 'role_translations', 'notification_template_translations', 'page_translations', 'team_member_translations'] as $table) {
        expect(DB::table($table)->where('locale', 'fr')->count())->toBe(0);
    }
});

test('the fields an item offers come from its metadata, and a page never offers its slug', function (): void {
    englishAsSource();
    $token = tokenWithPermissions([PagePermission::VIEW->value]);

    englishPage(['title' => 'About us', 'body' => '<p>Who we are.</p>'], ['title' => 'About us | AlphaMaster']);

    $entry = $this->withToken($token)->getJson('/api/v1/admin/translations?target=ar&source=pages')->assertOk()->json('data.entries.0');

    $fields = collect($entry['fields'])->keyBy('name');

    expect($fields->keys()->all())->toBe(['title', 'summary', 'body', 'seo_title', 'seo_description'])
        ->and($fields['title'])->toMatchArray(['required' => true, 'type' => 'plain_text', 'group' => 'content', 'max_length' => 200])
        ->and($fields['body'])->toMatchArray(['required' => true, 'type' => 'html', 'group' => 'content', 'multiline' => true])
        ->and($fields['seo_title'])->toMatchArray(['required' => false, 'type' => 'plain_text', 'group' => 'seo', 'max_length' => 255])
        ->and($entry['progress'])->toBe(['filled' => 0, 'total' => 5, 'complete' => false]);
});

// ── Item status ──────────────────────────────────────────────────────────────

test('an item missing a required field is incomplete', function (): void {
    englishAsSource();
    $token = tokenWithPermissions([PagePermission::VIEW->value]);

    $page = englishPage(['title' => 'About us', 'body' => '<p>Who we are.</p>']);
    app(PageService::class)->writeTranslation($page, 'ar', ['title' => 'من نحن'], null, null);

    $entry = $this->withToken($token)->getJson('/api/v1/admin/translations?target=ar&source=pages')->assertOk()->json('data.entries.0');

    expect($entry['status'])->toBe('incomplete')
        ->and($entry['progress'])->toBe(['filled' => 1, 'total' => 5, 'complete' => false]);
});

test('empty optional SEO fields do not keep an item from being translated', function (): void {
    englishAsSource();
    aiAnswering('لا ينبغي أن يُطلب');
    $token = tokenWithPermissions([PagePermission::VIEW->value, PagePermission::UPDATE->value, 'ai.use']);

    $page = englishPage(['title' => 'About us', 'body' => '<p>Who we are.</p>'], ['title' => 'About us | AlphaMaster']);
    app(PageService::class)->writeTranslation($page, 'ar', ['title' => 'من نحن', 'body' => '<p>من نكون.</p>'], null, null);

    $response = $this->withToken($token)->getJson('/api/v1/admin/translations?target=ar&source=pages')->assertOk();
    $entry = $response->json('data.entries.0');

    expect($entry['status'])->toBe('translated')
        ->and($entry['progress'])->toBe(['filled' => 2, 'total' => 5, 'complete' => true])
        ->and($response->json('data.sources.0.completeness'))->toBe(['total' => 1, 'translated' => 1]);

    // Translated, so "all missing" leaves it alone and nobody is billed for its SEO.
    translateWithAi($this, $token)->assertOk()->assertJsonPath('data.queued', 0);

    expect(TranslationBatch::query()->count())->toBe(0)
        ->and(sentToProvider())->toBe([]);
});

// ── Translate all missing ────────────────────────────────────────────────────

test('translate all missing starts one batch per item that needs one, and asks about no field', function (): void {
    englishAsSource();
    siteNameInEnglish();
    $template = templateWithoutLocale('ar');
    aiAnswering(fn (string $text): string => 'AR '.$text);

    $token = tokenWithPermissions(['settings.view', 'settings.update', 'notifications.view', 'notifications.update', 'ai.use']);

    // One command for the whole language: no source, no item, no field.
    $response = translateWithAi($this, $token)->assertOk();

    $batches = TranslationBatch::query()->get()->keyBy('item_id');

    expect($batches->keys()->sort()->values()->all())->toBe(collect(['general.site_name', (string) $template->getKey()])->sort()->values()->all())
        ->and($response->json('data.queued'))->toBe(2)
        ->and($batches[(string) $template->getKey()]->fields_total)->toBe(2)
        ->and($batches->every(fn (TranslationBatch $batch): bool => $batch->status === BatchStatus::READY))->toBeTrue()
        ->and(TranslationSuggestion::query()->whereNull('batch_id')->count())->toBe(0)
        // Generated is not translated: nothing is written until somebody accepts.
        ->and($template->refresh()->translations->firstWhere('locale', 'ar'))->toBeNull();

    $ready = $this->withToken($token)->getJson('/api/v1/admin/translations?target=ar&state=ready&per_page=100')->assertOk();

    expect(array_column($ready->json('data.entries'), 'id'))->toContain((string) $template->getKey())
        ->and($ready->json('data.entries.0.batch.status'))->toBe('ready');
});

test('a batch is a real record of who asked, what it covers and how far it got', function (): void {
    englishAsSource();
    editorInEnglish();
    aiAnswering('محرّر');

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);
    translateWithAi($this, $token, ['source' => 'roles'])->assertOk();

    $batch = TranslationBatch::query()->sole();

    expect($batch->source_key)->toBe('roles')
        ->and($batch->locale)->toBe('ar')
        ->and($batch->requested_by)->not->toBeNull()
        ->and($batch->fields_total)->toBe(1)
        ->and($batch->fields_ready)->toBe(1)
        ->and($batch->fields_failed)->toBe(0)
        ->and($batch->completed_at)->not->toBeNull()
        ->and($batch->accepted_by)->toBeNull();
});

test('pressing Translate twice does not start a second batch or cost twice', function (): void {
    englishAsSource();
    $role = editorInEnglish();
    aiAnswering('محرّر');

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);
    $item = ['source' => 'roles', 'item' => (string) $role->getKey()];

    translateWithAi($this, $token, $item)->assertOk()->assertJsonPath('data.queued', 1);

    translateWithAi($this, $token, $item)
        ->assertOk()
        ->assertJsonPath('data.queued', 0)
        ->assertJsonPath('data.existing', 1);

    expect(TranslationBatch::query()->count())->toBe(1)
        ->and(sentToProvider())->toHaveCount(1);

    // And the database holds the line when two requests race past the check.
    $batch = TranslationBatch::query()->sole();

    expect(fn () => DB::transaction(fn () => TranslationBatch::query()->create([
        'source_key' => $batch->source_key,
        'item_id' => $batch->item_id,
        'locale' => $batch->locale,
        'status' => BatchStatus::PENDING,
    ])))->toThrow(UniqueConstraintViolationException::class);
});

test('the target is the language named in the request, whatever language the console speaks', function (): void {
    englishAsSource();
    $role = editorInEnglish();
    languageAdded('fr', 'French', 'Français');
    aiAnswering('Éditeur');

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);

    translateWithAi($this, $token, ['locale' => 'fr', 'source' => 'roles'])
        ->assertOk();

    $batch = TranslationBatch::query()->sole();

    $this->withToken($token)
        ->withHeader('X-Locale', 'ar')
        ->withHeader('Accept-Language', 'ar')
        ->postJson('/api/v1/admin/translations/batches/'.$batch->getKey().'/accept')
        ->assertOk();

    $written = $role->refresh()->translations->pluck('label', 'locale')->all();

    expect($batch->locale)->toBe('fr')
        ->and($written)->toHaveKey('fr')
        ->and($written['fr'])->toBe('Éditeur')
        ->and($written)->not->toHaveKey('ar');
});

test('content is never translated into the language it is written in', function (): void {
    englishAsSource();
    editorInEnglish();
    aiAnswering('x');

    translateWithAi($this, tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']), ['locale' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.queued', 0);

    expect(TranslationBatch::query()->count())->toBe(0)
        ->and(sentToProvider())->toBe([]);
});

test('a platform with no AI provider says so rather than starting work that will fail', function (): void {
    englishAsSource();

    translateWithAi($this, tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'AI_NOT_CONFIGURED');

    expect(TranslationBatch::query()->count())->toBe(0);
});

// ── Accept, once per item ────────────────────────────────────────────────────

test('a notification template is accepted once, subject and body together', function (): void {
    englishAsSource();
    $template = templateWithoutLocale('ar');
    aiAnswering(fn (string $text): string => 'AR '.$text);

    $token = tokenWithPermissions(['notifications.view', 'notifications.update', 'ai.use']);
    AuditRecord::query()->getQuery()->delete();

    translateWithAi($this, $token, ['source' => 'notification-templates', 'item' => (string) $template->getKey()])
        ->assertOk()
        ->assertJsonPath('data.queued', 1);

    $batch = TranslationBatch::query()->sole();

    expect($batch->status)->toBe(BatchStatus::READY)->and($batch->fields_total)->toBe(2);

    // Accepting the subject on its own is what used to reach the template's rule that a
    // subject needs its body — and fail with 422. The item is written whole instead.
    acceptTranslation($this, $token, $batch)->assertOk()->assertJsonPath('data.status', 'accepted');

    $arabic = $template->refresh()->translations->firstWhere('locale', 'ar');
    $record = AuditRecord::query()->where('action', AuditAction::TRANSLATION_UPDATED)->sole();

    expect($arabic)->not->toBeNull()
        ->and((string) $arabic->getAttribute('subject'))->toStartWith('AR ')
        ->and((string) $arabic->getAttribute('body'))->toStartWith('AR ')
        ->and($batch->refresh()->status)->toBe(BatchStatus::ACCEPTED)
        ->and($batch->accepted_by)->not->toBeNull()
        ->and(TranslationSuggestion::query()->where('batch_id', $batch->getKey())->get()->every(
            fn (TranslationSuggestion $row): bool => $row->status === SuggestionStatus::ACCEPTED
        ))->toBeTrue()
        ->and($record->context['fields'])->toBe(['subject', 'body'])
        ->and($record->context['origin'])->toBe('ai')
        ->and($record->context['edited'])->toBeFalse();
});

test('what a reviewer edits is what is written, and the item is recorded as edited', function (): void {
    englishAsSource();
    $role = editorInEnglish();
    aiAnswering('مُحرِّر');

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);
    translateWithAi($this, $token, ['source' => 'roles'])->assertOk();

    $batch = TranslationBatch::query()->sole();

    acceptTranslation($this, $token, $batch, ['label' => 'محرّر'])
        ->assertOk()
        ->assertJsonPath('data.edited', true);

    expect($role->refresh()->translate('label', 'ar'))->toBe('محرّر')
        ->and(TranslationSuggestion::query()->sole()->edited)->toBeTrue();
});

test('a field that is not part of the translation cannot be sent with it', function (): void {
    englishAsSource();
    editorInEnglish();
    aiAnswering('محرّر');

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);
    translateWithAi($this, $token, ['source' => 'roles'])->assertOk();

    acceptTranslation($this, $token, TranslationBatch::query()->sole(), ['slug' => 'editor'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'UNKNOWN_TRANSLATION_FIELD');
});

test('a required field cannot be accepted empty, and a field cannot be accepted longer than it allows', function (): void {
    englishAsSource();
    editorInEnglish();
    aiAnswering('محرّر');

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);
    translateWithAi($this, $token, ['source' => 'roles'])->assertOk();

    $batch = TranslationBatch::query()->sole();

    acceptTranslation($this, $token, $batch, ['label' => ' '])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'TRANSLATION_REQUIRED_EMPTY');

    acceptTranslation($this, $token, $batch, ['label' => str_repeat('م', 151)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'TRANSLATION_TOO_LONG');

    expect($batch->refresh()->status)->toBe(BatchStatus::READY);
});

test('an item written by hand while its translation waited cannot be accepted over', function (): void {
    englishAsSource();
    $role = editorInEnglish();
    aiAnswering('اقتراح');

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);
    translateWithAi($this, $token, ['source' => 'roles'])->assertOk();

    $role->setTranslation('ar', ['label' => 'كتبها إنسان أثناء الانتظار']);

    acceptTranslation($this, $token, TranslationBatch::query()->sole())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRANSLATION_MOVED');

    expect($role->refresh()->translate('label', 'ar'))->toBe('كتبها إنسان أثناء الانتظار');
});

test('accept all ready works item by item, and one refused item does not stop the others', function (): void {
    englishAsSource();
    siteNameInEnglish();
    $editor = editorInEnglish();

    /** @var Role $other */
    $other = Role::query()->where('name', '!=', 'editor')->orderBy('name')->firstOrFail();
    $other->setTranslation('en', ['label' => 'Other']);

    aiAnswering(fn (string $text): string => 'AR '.$text);

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'settings.view', 'settings.update', 'ai.use']);
    translateWithAi($this, $token)->assertOk()->assertJsonPath('data.queued', 3);

    // One of the three is written by hand while it waits.
    $editor->setTranslation('ar', ['label' => 'كتبها إنسان']);

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/translations/batches/accept-ready', ['locale' => 'ar'])
        ->assertOk();

    $results = collect($response->json('data.results'))->keyBy('item_id');

    expect($response->json('data.accepted'))->toBe(2)
        ->and($response->json('data.failed'))->toBe(1)
        ->and($response->json('message'))->toBe('2 accepted / 1 failed.')
        ->and($results[(string) $editor->getKey()]['status'])->toBe('failed')
        ->and($results[(string) $editor->getKey()]['error_code'])->toBe('TRANSLATION_MOVED')
        ->and($results[(string) $editor->getKey()]['message'])->not->toBeNull()
        ->and($results[(string) $other->getKey()]['status'])->toBe('accepted')
        ->and($results['general.site_name']['status'])->toBe('accepted')
        ->and($other->refresh()->translate('label', 'ar'))->toBe('AR Other')
        ->and($editor->refresh()->translate('label', 'ar'))->toBe('كتبها إنسان');
});

test('dismissing a translation writes nothing', function (): void {
    englishAsSource();
    siteNameInEnglish();
    aiAnswering('ألفاماستر');

    $token = tokenWithPermissions(['settings.view', 'settings.update', 'ai.use']);
    translateWithAi($this, $token, ['source' => 'settings'])->assertOk();

    $batch = TranslationBatch::query()->sole();

    $this->withToken($token)->deleteJson('/api/v1/admin/translations/batches/'.$batch->getKey())->assertOk();

    /** @var Setting $setting */
    $setting = Setting::query()->where('group', 'general')->where('key', 'site_name')->sole();

    expect($batch->refresh()->status)->toBe(BatchStatus::DISMISSED)
        ->and($setting->translations->firstWhere('locale', 'ar'))->toBeNull();
});

test('there is no endpoint that translates or accepts a single field', function (): void {
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/v1/admin/translations'))
        ->implode(' ');

    expect($uris)->not->toContain('suggestion')->not->toContain('field');
});

// ── Failure ──────────────────────────────────────────────────────────────────

test('one field failing fails the item with its reason, and a retry asks only for what failed', function (): void {
    englishAsSource();
    $template = templateWithoutLocale('ar');
    openAiConfigured();

    $english = $template->translations()->where('locale', 'en')->firstOrFail();
    $subject = (string) $english->getAttribute('subject');
    $failBody = true;

    Http::fake(['api.openai.com/*' => function (HttpRequest $request) use (&$failBody, $subject) {
        $content = sentContent($request);

        if ($failBody && $content !== $subject) {
            return Http::response(['error' => ['code' => 'model_not_found', 'message' => 'No such model.']], 404);
        }

        return Http::response(['choices' => [['message' => ['content' => 'AR '.$content]]], 'usage' => ['total_tokens' => 9]], 200);
    }]);

    $token = tokenWithPermissions(['notifications.view', 'notifications.update', 'ai.use']);
    $item = ['source' => 'notification-templates', 'item' => (string) $template->getKey()];

    translateWithAi($this, $token, $item)->assertOk();

    $batch = TranslationBatch::query()->sole();

    // Not a success with a gap in it.
    expect($batch->status)->toBe(BatchStatus::FAILED)
        ->and($batch->fields_ready)->toBe(1)
        ->and($batch->fields_failed)->toBe(1)
        ->and($batch->error_code)->toBe('model_not_found')
        ->and($batch->error_message)->toBe('No such model.');

    $entry = $this->withToken($token)
        ->getJson('/api/v1/admin/translations?target=ar&source=notification-templates&state=failed')
        ->assertOk()
        ->json('data.entries.0');

    expect($entry['status'])->toBe('failed')
        ->and($entry['batch']['error_message'])->toBe('No such model.');

    acceptTranslation($this, $token, $batch)->assertStatus(409)->assertJsonPath('error.code', 'TRANSLATION_NOT_READY');

    // Retry the item.
    $failBody = false;
    translateWithAi($this, $token, $item)->assertOk()->assertJsonPath('data.queued', 1);

    $subjectAsks = collect(sentToProvider())->filter(fn (HttpRequest $request): bool => sentContent($request) === $subject)->count();

    expect($batch->refresh()->status)->toBe(BatchStatus::READY)
        ->and(TranslationBatch::query()->count())->toBe(1)
        // The subject that arrived the first time was kept, not paid for again.
        ->and($subjectAsks)->toBe(1);

    acceptTranslation($this, $token, $batch)->assertOk();
});

test('a vendor error that quotes the key is kept on the item without it', function (): void {
    englishAsSource();

    $key = implode('-', ['sk', 'proj', str_repeat('Kp2', 12)]);

    IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->update(['is_default' => false]);

    /** @var IntegrationProvider $provider */
    $provider = IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->where('driver', 'openai')->firstOrFail();
    $provider->setCredentials(['api_key' => $key]);
    $provider->forceFill(['is_active' => true, 'is_default' => true])->save();

    Http::fake(['api.openai.com/*' => Http::response(['error' => [
        'code' => 'invalid_api_key',
        'message' => "Incorrect API key provided: {$key}. Authorization: Bearer {$key}",
    ]], 401)]);

    editorInEnglish();

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);
    translateWithAi($this, $token, ['source' => 'roles'])->assertOk();

    $batch = TranslationBatch::query()->sole();
    $listed = $this->withToken($token)->getJson('/api/v1/admin/translations?target=ar&source=roles&state=failed')->assertOk()->content();

    expect($batch->status)->toBe(BatchStatus::FAILED)
        ->and($batch->error_code)->toBe('invalid_api_key')
        ->and($batch->error_message)->toContain('Incorrect API key provided');

    foreach ([(string) $batch->error_message, $listed] as $text) {
        expect($text)->not->toContain($key)->not->toContain(substr($key, -6));
    }
});

test('a job that dies with a secret in its exception fails its item without the secret', function (): void {
    $secret = implode('-', ['sk', 'ant', str_repeat('Dz5', 12)]);

    $batch = TranslationBatch::query()->create([
        'source_key' => 'roles',
        'item_id' => '1',
        'locale' => 'ar',
        'status' => BatchStatus::PENDING,
        'fields_total' => 1,
    ]);

    $suggestion = TranslationSuggestion::query()->create([
        'batch_id' => $batch->getKey(),
        'source_key' => 'roles',
        'item_id' => '1',
        'field' => 'label',
        'locale' => 'ar',
        'status' => SuggestionStatus::PENDING,
        'source_text' => 'Editor',
    ]);

    (new GenerateTranslationSuggestion((string) $suggestion->getKey()))
        ->failed(new RuntimeException("Worker died calling https://api.example.test/v1?key={$secret} with x-api-key: {$secret}"));

    expect($suggestion->refresh()->status)->toBe(SuggestionStatus::FAILED)
        ->and($batch->refresh()->status)->toBe(BatchStatus::FAILED)
        ->and($batch->error_code)->toBe('JOB_FAILED')
        ->and($batch->error_message)->toContain('key=[redacted]')
        ->not->toContain($secret);
});

// ── How each kind of field is translated ─────────────────────────────────────

test('an HTML field comes back with its tags and links, through its own instruction', function (): void {
    englishAsSource();
    $page = englishPage(['title' => 'About us', 'body' => '<p>Welcome to <a href="https://example.test/about">our site</a>.</p>']);

    aiAnswering(fn (string $text): string => str_contains($text, '<p>')
        ? str_replace(['Welcome to', 'our site'], ['Bienvenue sur', 'notre site'], $text)
        : 'À propos de nous');

    languageAdded('fr', 'French', 'Français');
    $token = tokenWithPermissions([PagePermission::VIEW->value, PagePermission::UPDATE->value, 'ai.use']);

    translateWithAi($this, $token, ['locale' => 'fr', 'source' => 'pages', 'item' => $page->id])->assertOk();

    $batch = TranslationBatch::query()->sole();
    $instructions = collect(sentToProvider())->mapWithKeys(fn (HttpRequest $request): array => [sentContent($request) => sentInstruction($request)]);
    $body = (string) $instructions->keys()->first(fn (string $text): bool => str_contains($text, '<p>'));

    expect($batch->status)->toBe(BatchStatus::READY)
        ->and($instructions[$body])->toContain('HTML fragment')
        ->and($instructions['About us'])->not->toContain('HTML fragment');

    acceptTranslation($this, $token, $batch)->assertOk();

    /** @var PageTranslation $french */
    $french = PageTranslation::query()->where('page_id', $page->id)->where('locale', 'fr')->sole();

    expect($french->body)->toContain('href="https://example.test/about"')->toContain('notre site');
});

test('an HTML translation that loses a link fails instead of being offered', function (): void {
    englishAsSource();
    $page = englishPage(['title' => 'About us', 'body' => '<p>Welcome to <a href="https://example.test/about">our site</a>.</p>']);

    aiAnswering(fn (string $text): string => str_contains($text, '<p>') ? '<p>Bienvenue sur notre site.</p>' : 'À propos');

    $token = tokenWithPermissions([PagePermission::VIEW->value, PagePermission::UPDATE->value, 'ai.use']);
    translateWithAi($this, $token, ['source' => 'pages', 'item' => $page->id])->assertOk();

    $body = TranslationSuggestion::query()->where('field', 'body')->sole();

    expect($body->status)->toBe(SuggestionStatus::FAILED)
        ->and($body->error_code)->toBe('HTML_STRUCTURE_CHANGED')
        ->and(TranslationBatch::query()->sole()->status)->toBe(BatchStatus::FAILED);
});

test('an SEO field is translated within its length, and one that comes back longer fails', function (): void {
    englishAsSource();
    $page = englishPage(['title' => 'About us', 'body' => '<p>Who we are.</p>'], ['title' => 'About us | AlphaMaster']);

    aiAnswering(fn (string $text): string => match ($text) {
        'About us | AlphaMaster' => str_repeat('س', 300),
        'About us' => 'من نحن',
        default => '<p>من نكون.</p>',
    });

    $token = tokenWithPermissions([PagePermission::VIEW->value, PagePermission::UPDATE->value, 'ai.use']);
    translateWithAi($this, $token, ['source' => 'pages', 'item' => $page->id])->assertOk();

    $seo = TranslationSuggestion::query()->where('field', 'seo_title')->sole();
    $asked = collect(sentToProvider())->first(fn (HttpRequest $request): bool => sentContent($request) === 'About us | AlphaMaster');

    expect(sentInstruction($asked))->toContain('search engine metadata')->toContain('Maximum length: 255 characters')
        ->and($seo->field_group)->toBe(FieldGroup::SEO)
        ->and($seo->status)->toBe(SuggestionStatus::FAILED)
        ->and($seo->error_code)->toBe('TRANSLATION_TOO_LONG');
});

test('the slug is never sent to the provider, and the page makes its own from the translated title', function (): void {
    englishAsSource();
    $page = englishPage(['title' => 'About us', 'body' => '<p>Who we are.</p>']);

    aiAnswering(fn (string $text): string => str_contains($text, '<p>') ? '<p>Qui nous sommes.</p>' : 'Qui sommes-nous');
    languageAdded('fr', 'French', 'Français');

    $token = tokenWithPermissions([PagePermission::VIEW->value, PagePermission::UPDATE->value, 'ai.use']);
    translateWithAi($this, $token, ['locale' => 'fr', 'source' => 'pages'])->assertOk();

    $batch = TranslationBatch::query()->sole();

    expect(TranslationSuggestion::query()->pluck('field')->sort()->values()->all())->toBe(['body', 'title']);

    foreach (sentToProvider() as $request) {
        expect(json_encode($request->data()))->not->toContain('about-us')->not->toContain('slug');
    }

    acceptTranslation($this, $token, $batch)->assertOk();

    /** @var PageTranslation $french */
    $french = PageTranslation::query()->where('page_id', $page->id)->where('locale', 'fr')->sole();

    expect($french->slug)->not->toBeNull()
        ->and($french->slug)->not->toBe('about-us')
        // Translation is not publication.
        ->and($page->refresh()->status)->toBe(PageStatus::DRAFT);
});

test('a field is given an output budget that grows with its source, not one ceiling for everything', function (): void {
    englishAsSource();
    $long = '<p>'.str_repeat('Lorem ipsum dolor sit amet. ', 150).'</p>';
    $page = englishPage(['title' => 'About us', 'body' => $long]);

    aiAnswering(fn (string $text): string => $text);

    $token = tokenWithPermissions([PagePermission::VIEW->value, PagePermission::UPDATE->value, 'ai.use']);
    translateWithAi($this, $token, ['source' => 'pages', 'item' => $page->id])->assertOk();

    $budgets = collect(sentToProvider())->mapWithKeys(fn (HttpRequest $request): array => [
        (str_contains(sentContent($request), '<p>') ? 'body' : 'title') => $request->data()['max_tokens'],
    ]);

    expect($budgets['title'])->toBe(512)
        ->and($budgets['body'])->toBeGreaterThan(512)
        ->and($budgets['body'])->toBeLessThanOrEqual(TranslationPrompt::OUTPUT_CEILING)
        ->and(TranslationPrompt::outputBudget(str_repeat('x', 50000), FieldType::HTML, 512))->toBe(TranslationPrompt::OUTPUT_CEILING);
});

test('each kind of field has its own instruction, and every one protects placeholders', function (): void {
    $language = fn (string $code): Language => new Language(['code' => $code, 'name' => $code, 'native_name' => $code, 'direction' => 'ltr']);

    $build = fn (FieldType $type, FieldGroup $group): TextGenerationRequest => TranslationPrompt::for(
        'Hello :name', $language('xx'), $language('yy'), 'Label', $type, $group, null, 512
    );

    $plain = $build(FieldType::PLAIN_TEXT, FieldGroup::CONTENT)->instruction;
    $html = $build(FieldType::HTML, FieldGroup::CONTENT)->instruction;
    $seo = $build(FieldType::PLAIN_TEXT, FieldGroup::SEO)->instruction;

    expect([$plain, $html, $seo])->each->toContain(':name')
        ->and($plain)->not->toBe($html)->not->toBe($seo)
        ->and($html)->toContain('href')
        ->and($seo)->toContain('maximum length');
});

test('HTML fidelity allows words to move and refuses markup that changed', function (): void {
    $source = '<p>Read <strong>the guide</strong> at <a href="https://example.test/g" class="x">our site</a>.</p>';

    expect(HtmlFidelity::preserved($source, '<p>Lisez <a href="https://example.test/g" class="x">notre site</a> et <strong>le guide</strong>.</p>'))->toBeTrue()
        ->and(HtmlFidelity::preserved('<img src="/a.png" alt="A cat">', '<img src="/a.png" alt="Un chat">'))->toBeTrue()
        ->and(HtmlFidelity::preserved($source, '<p>Lisez le guide sur notre site.</p>'))->toBeFalse()
        ->and(HtmlFidelity::preserved($source, '<p>Lisez <strong>le guide</strong> sur <a href="https://example.test/fr" class="x">notre site</a>.</p>'))->toBeFalse()
        ->and(TranslationPrompt::clean("```html\n<p>x</p>\n```"))->toBe('<p>x</p>');
});

// ── Pages, Team and Settings in a new language, with no setup ────────────────

test('pages, team profiles and settings are translated into a newly added language, and nothing is published', function (): void {
    englishAsSource();
    siteNameInEnglish();
    $page = englishPage(['title' => 'About us', 'body' => '<p>Who we are.</p>']);
    $member = englishTeamMember('Nadia Haddad', 'Editor');

    aiAnswering(fn (string $text): string => 'FR '.$text);

    $token = tokenWithPermissions([
        'settings.view', 'settings.update',
        PagePermission::VIEW->value, PagePermission::UPDATE->value,
        TeamPermission::VIEW->value, TeamPermission::UPDATE->value,
        'ai.use',
    ]);

    $this->withToken($token)->postJson('/api/v1/admin/languages', [
        'code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'direction' => 'ltr', 'is_active' => false,
    ])->assertCreated();

    translateWithAi($this, $token, ['locale' => 'fr'])->assertOk()->assertJsonPath('data.queued', 3);

    $this->withToken($token)
        ->postJson('/api/v1/admin/translations/batches/accept-ready', ['locale' => 'fr'])
        ->assertOk()
        ->assertJsonPath('data.accepted', 3)
        ->assertJsonPath('data.failed', 0);

    $sources = collect($this->withToken($token)->getJson('/api/v1/admin/translations?target=fr')->assertOk()->json('data.sources'))->keyBy('key');

    /** @var TeamMemberTranslation $profile */
    $profile = TeamMemberTranslation::query()->where('team_member_id', $member->id)->where('locale', 'fr')->sole();

    expect($sources['pages']['statuses']['translated'])->toBe(1)
        ->and($sources['team']['statuses']['translated'])->toBe(1)
        ->and($sources['settings']['statuses']['translated'])->toBe(1)
        ->and($profile->name)->toBe('FR Nadia Haddad')
        ->and($profile->slug)->not->toBeNull()
        ->and($page->refresh()->status)->toBe(PageStatus::DRAFT)
        ->and($member->refresh()->is_active)->toBeFalse()
        ->and(Language::query()->where('code', 'fr')->value('is_active'))->toBeFalse();
});

// ── The boundary ─────────────────────────────────────────────────────────────

test('asking needs the permission that governs spending', function (): void {
    englishAsSource();
    editorInEnglish();
    aiAnswering('x');

    translateWithAi($this, tokenWithPermissions(['roles.view', 'roles.update']), ['source' => 'roles'])->assertForbidden();

    expect(TranslationBatch::query()->count())->toBe(0);
});

test('nothing is translated for content the requester may not write', function (): void {
    englishAsSource();
    siteNameInEnglish();
    templateWithoutLocale('ar');
    editorInEnglish();
    aiAnswering('x');

    translateWithAi($this, tokenWithPermissions(['settings.view', 'settings.update', 'ai.use']))->assertOk();

    expect(TranslationBatch::query()->pluck('source_key')->unique()->values()->all())->toBe(['settings']);
});

test('what AI generated is shown only to somebody who may write its content', function (): void {
    englishAsSource();
    templateWithoutLocale('ar');
    // A translation that keeps the template's placeholders, as any accepted one must.
    aiAnswering(fn (string $text): string => 'AR '.$text);

    $writer = tokenWithPermissions(['notifications.view', 'notifications.update', 'ai.use']);
    translateWithAi($this, $writer, ['source' => 'notification-templates'])->assertOk();

    resetClient($this);
    $reader = tokenWithPermissions(['notifications.view']);

    $entries = $this->withToken($reader)
        ->getJson('/api/v1/admin/translations?target=ar&source=notification-templates&per_page=100')
        ->assertOk()
        ->json('data.entries');

    expect(array_filter(array_column($entries, 'batch')))->toBe([])
        ->and(array_column($entries, 'status'))->not->toContain('ready');
});

test('accepting needs the content’s own write permission', function (): void {
    englishAsSource();
    templateWithoutLocale('ar');
    // A translation that keeps the template's placeholders, as any accepted one must.
    aiAnswering(fn (string $text): string => 'AR '.$text);

    translateWithAi($this, tokenWithPermissions(['notifications.view', 'notifications.update', 'ai.use']), ['source' => 'notification-templates'])->assertOk();

    $batch = TranslationBatch::query()->firstOrFail();

    resetClient($this);

    acceptTranslation($this, tokenWithPermissions(['settings.view', 'settings.update', 'ai.use']), $batch)->assertForbidden();

    expect($batch->refresh()->status)->toBe(BatchStatus::READY);
});

test('the translation routes are behind the administrative perimeter', function (): void {
    $this->postJson('/api/v1/admin/translations/batches', ['locale' => 'ar'])->assertUnauthorized();
    $this->postJson('/api/v1/admin/translations/batches/accept-ready', ['locale' => 'ar'])->assertUnauthorized();
    $this->postJson('/api/v1/admin/translations/batches/'.Str::ulid().'/accept')->assertUnauthorized();
});

// ── Which provider answers, and with which model ────────────────────────────

/**
 * Two providers set up side by side, each with its own key and model — the way an operator
 * leaves them after configuring both. Neither is made the default here.
 */
function twoConfiguredProviders(): void
{
    foreach (['openai' => 'gpt-5.6-terra', 'anthropic' => 'claude-haiku-4-5'] as $driver => $model) {
        /** @var IntegrationProvider $provider */
        $provider = IntegrationProvider::query()
            ->forCapability(IntegrationCapability::AI)
            ->where('driver', $driver)
            ->firstOrFail();

        $provider->setCredentials(['api_key' => 'key-for-'.$driver]);
        $provider->forceFill(['is_active' => true, 'settings' => ['model' => $model]])->save();
    }

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'محرّر']]],
            'usage' => ['total_tokens' => 9],
        ]),
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'محرّر']],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
        ]),
    ]);
}

test('a translation is generated by the default provider with that provider’s own model', function (?string $oldGlobalModel): void {
    englishAsSource();
    twoConfiguredProviders();

    if ($oldGlobalModel !== null) {
        DB::table('settings')->insert([
            'id' => (string) Str::ulid(),
            'group' => 'ai',
            'key' => 'translation_model',
            'value' => json_encode($oldGlobalModel),
            'type' => 'string',
            'is_secret' => false,
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::flush();
    }

    $role = editorInEnglish();

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use', 'integrations.view', 'integrations.update']);
    $item = ['source' => 'roles', 'item' => (string) $role->getKey()];

    $this->withToken($token)->postJson('/api/v1/admin/ai/providers/anthropic/default')->assertOk();
    translateWithAi($this, $token, $item)->assertOk();

    $first = TranslationBatch::query()->sole();
    expect($first->status)->toBe(BatchStatus::READY);

    $this->withToken($token)->deleteJson('/api/v1/admin/translations/batches/'.$first->getKey())->assertSuccessful();
    $this->withToken($token)->postJson('/api/v1/admin/ai/providers/openai/default')->assertOk();
    translateWithAi($this, $token, $item)->assertOk();

    $sent = Http::recorded()
        ->map(fn (array $pair): array => [parse_url($pair[0]->url(), PHP_URL_HOST), $pair[0]->data()['model'] ?? null])
        ->values()
        ->all();

    expect($sent)->toBe([
        ['api.anthropic.com', 'claude-haiku-4-5'],
        ['api.openai.com', 'gpt-5.6-terra'],
    ]);
})->with([
    'with the old global model still stored' => ['gpt-4o-mini'],
    'with no old global model' => [null],
]);
