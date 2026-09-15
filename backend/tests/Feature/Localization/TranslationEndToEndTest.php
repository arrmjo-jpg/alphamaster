<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Authorization\Models\Role;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Enums\BatchStatus;
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\TranslationBatch;
use App\Modules\Localization\Models\TranslationSuggestion;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Notification\Models\NotificationTemplate;
use App\Modules\Pages\Enums\PagePermission;
use App\Modules\Pages\Enums\PageStatus;
use App\Modules\Pages\Models\Page;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Team\Enums\TeamPermission;
use App\Modules\Team\Models\TeamMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    Language::query()->update(['is_default' => false]);
    Language::query()->where('code', 'en')->update(['is_default' => true, 'is_active' => true]);
    Cache::flush();
});

// The whole of ADR 0056, end to end, through the API an operator's console uses and nothing
// else: a language nobody wrote into the code is added, every body of content offers itself in
// it, "translate all missing" is one request, every item comes back ready for review, each is
// accepted once, and what is left behind in the database is exactly what that should produce.
//
// The language code is made up when the test runs, so no part of the platform can know it in
// advance. The vendor is faked at the wire; everything between the request and the database
// is real.

test('a language nobody coded for is translated end to end, item by item, with no step per field', function (): void {
    $code = 'q'.Str::lower(Str::random(3));
    expect(Language::query()->where('code', $code)->exists())->toBeFalse();

    // ── The platform's content, in its own language ─────────────────────────
    $token = tokenWithPermissions([
        'settings.view', 'settings.update',
        'roles.view', 'roles.update',
        'notifications.view', 'notifications.update',
        PagePermission::VIEW->value, PagePermission::CREATE->value, PagePermission::UPDATE->value, PagePermission::PUBLISH->value,
        TeamPermission::VIEW->value, TeamPermission::CREATE->value, TeamPermission::UPDATE->value,
        'ai.use', 'languages.manage',
    ]);

    $this->withToken($token)
        ->withHeader('If-Match', '"'.settingsVersion('general').'"')
        ->putJson('/api/v1/admin/settings/general', ['settings' => ['site_name' => 'AlphaMaster'], 'locale' => 'en'])
        ->assertOk();

    /** @var Role $editor */
    $editor = Role::query()->where('name', 'editor')->sole();
    $this->withToken($token)->putJson('/api/v1/admin/translations/roles/'.$editor->getKey(), ['locale' => 'en', 'values' => ['label' => 'Editor']])->assertOk();

    $pageId = (string) $this->withToken($token)->postJson('/api/v1/admin/pages', ['sort_order' => 0])->assertCreated()->json('data.id');
    $this->withToken($token)->putJson("/api/v1/admin/pages/{$pageId}/translations/en", [
        'title' => 'About us',
        'body' => '<p>Who we are, and <a href="https://example.test/contact">how to reach us</a>.</p>',
        'seo' => ['title' => 'About AlphaMaster'],
    ])->assertOk();

    $memberId = (string) $this->withToken($token)->postJson('/api/v1/admin/team', [])->assertCreated()->json('data.id');
    $this->withToken($token)->putJson("/api/v1/admin/team/{$memberId}/translations/en", [
        'name' => 'Nadia Haddad',
        'position' => 'Editor',
        'bio' => '<p>Writes <strong>everything</strong>.</p>',
    ])->assertOk();

    $templates = NotificationTemplate::query()->count();

    // ── The vendor ───────────────────────────────────────────────────────────
    IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->update(['is_default' => false]);
    /** @var IntegrationProvider $provider */
    $provider = IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->where('driver', 'openai')->firstOrFail();
    $provider->setCredentials(['api_key' => 'sk-not-a-real-key']);
    $provider->forceFill(['is_active' => true, 'is_default' => true])->save();

    Http::fake(['api.openai.com/*' => function (HttpRequest $request) use ($code) {
        $messages = $request->data()['messages'];
        $text = (string) end($messages)['content'];

        // A translation that keeps markup: the words change, the tags do not.
        return Http::response([
            'choices' => [['message' => ['content' => "[{$code}] ".$text]]],
            'usage' => ['total_tokens' => 9],
        ]);
    }]);

    AuditRecord::query()->getQuery()->delete();

    // ── 1. Add Language → Save ───────────────────────────────────────────────
    $this->withToken($token)->postJson('/api/v1/admin/languages', [
        'code' => $code, 'name' => 'Invented', 'native_name' => 'Invented', 'direction' => 'ltr', 'is_active' => false,
    ])->assertCreated();

    // ── 2. It is everywhere Language Management reaches, at once ─────────────
    expect(array_column($this->withToken($token)->getJson('/api/v1/admin/languages')->assertOk()->json('data'), 'code'))->toContain($code);

    $overview = collect($this->withToken($token)->getJson('/api/v1/admin/translations/overview')->assertOk()->json('data.languages'))->firstWhere('code', $code);
    expect($overview)->not->toBeNull()
        ->and($overview['coverage']['translated'])->toBe(0)
        ->and($overview['batches'])->toBe(['pending' => 0, 'ready' => 0, 'failed' => 0, 'accepted' => 0, 'dismissed' => 0]);

    $workshop = $this->withToken($token)->getJson("/api/v1/admin/translations?target={$code}&per_page=100")->assertOk();
    $items = count($workshop->json('data.entries'));

    expect(array_column($workshop->json('data.locales'), 'code'))->toContain($code)
        ->and(array_values(array_unique(array_column($workshop->json('data.entries'), 'status'))))->toBe(['not_translated'])
        ->and(array_column($workshop->json('data.sources'), 'key'))->toContain('pages', 'team', 'settings', 'notification-templates', 'roles');

    expect($this->withToken($token)->getJson('/api/v1/admin/pages')->assertOk()->json("data.0.progress.{$code}"))->toBe(['filled' => 0, 'total' => 4, 'complete' => false])
        ->and($this->withToken($token)->getJson('/api/v1/admin/team')->assertOk()->json("data.0.progress.{$code}"))->toBe(['filled' => 0, 'total' => 4, 'complete' => false]);

    $siteName = collect($this->withToken($token)->getJson("/api/v1/admin/settings/general?locale={$code}")->assertOk()->json('data'))->firstWhere('key', 'site_name');
    expect($siteName['locale'])->toBe($code)->and($siteName['translated'])->toBeFalse();

    // A draft is not served to the public, and no translation row was made to say "missing".
    expect(json_encode($this->getJson('/api/v1/languages')->json('data')))->not->toContain('"'.$code.'"');
    foreach (['setting_translations', 'role_translations', 'notification_template_translations', 'page_translations', 'team_member_translations'] as $table) {
        expect(DB::table($table)->where('locale', $code)->count())->toBe(0);
    }

    // The items that have something to translate from: the templates, the editor role, the
    // site name, the page and the member.
    $needed = $templates + 4;

    // ── 3. Translate all missing: one request, no item, no field ─────────────
    $this->withToken($token)->postJson('/api/v1/admin/translations/batches', ['locale' => $code])
        ->assertOk()
        ->assertJsonPath('data.queued', $needed);

    $calls = count(Http::recorded());

    // Pressed again: nothing new is started and nobody is billed.
    $this->withToken($token)->postJson('/api/v1/admin/translations/batches', ['locale' => $code])
        ->assertOk()
        ->assertJsonPath('data.queued', 0)
        ->assertJsonPath('data.existing', $needed);

    expect(count(Http::recorded()))->toBe($calls)
        ->and(TranslationBatch::query()->count())->toBe($needed)
        ->and(DB::table('translation_batches')->select('source_key', 'item_id', 'locale')->groupBy('source_key', 'item_id', 'locale')->havingRaw('count(*) > 1')->count())->toBe(0)
        ->and(DB::table('translation_suggestions')->select('source_key', 'item_id', 'field', 'locale')->groupBy('source_key', 'item_id', 'field', 'locale')->havingRaw('count(*) > 1')->count())->toBe(0)
        ->and(TranslationSuggestion::query()->whereNull('batch_id')->count())->toBe(0)
        // Generated for every field it needed, and never for a slug.
        ->and(TranslationSuggestion::query()->where('field', 'slug')->count())->toBe(0);

    foreach (Http::recorded() as [$request]) {
        expect(json_encode($request->data()))->not->toContain('about-us')->not->toContain('nadia-haddad');
    }

    // ── 4. Generated, and ready for review, as items ─────────────────────────
    expect(TranslationBatch::query()->where('status', '!=', BatchStatus::READY->value)->count())->toBe(0)
        ->and(TranslationSuggestion::query()->where('status', '!=', SuggestionStatus::READY->value)->count())->toBe(0);

    $ready = $this->withToken($token)->getJson("/api/v1/admin/translations?target={$code}&state=ready&per_page=100")->assertOk();
    expect($ready->json('data.pagination.total'))->toBe($needed);

    $pageEntry = collect($ready->json('data.entries'))->firstWhere('source', 'pages');
    $body = collect($pageEntry['batch']['suggestions'])->firstWhere('field', 'body');
    expect($pageEntry['status'])->toBe('ready')
        ->and(array_column($pageEntry['batch']['suggestions'], 'field'))->toEqualCanonicalizing(['title', 'body', 'seo_title'])
        ->and($body['text'])->toContain('href="https://example.test/contact"');

    // Nothing is written until somebody accepts.
    expect(DB::table('page_translations')->where('locale', $code)->count())->toBe(0);

    // ── 5. Accept: each template once — subject and body together ───────────
    $templateBatches = TranslationBatch::query()->where('source_key', 'notification-templates')->get();
    expect($templateBatches)->toHaveCount($templates);

    foreach ($templateBatches as $batch) {
        expect($batch->fields_total)->toBe(2);

        $this->withToken($token)->postJson("/api/v1/admin/translations/batches/{$batch->getKey()}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
    }

    // …and everything else that is ready, in one action, item by item.
    $this->withToken($token)->postJson('/api/v1/admin/translations/batches/accept-ready', ['locale' => $code])
        ->assertOk()
        ->assertJsonPath('data.accepted', $needed - $templates)
        ->assertJsonPath('data.failed', 0);

    // ── 6. What the database now holds ───────────────────────────────────────
    expect(TranslationBatch::query()->where('status', BatchStatus::ACCEPTED->value)->whereNotNull('accepted_by')->count())->toBe($needed)
        ->and(TranslationSuggestion::query()->where('status', '!=', SuggestionStatus::ACCEPTED->value)->count())->toBe(0)
        ->and(DB::table('notification_template_translations')->where('locale', $code)->whereNotNull('subject')->whereNotNull('body')->count())->toBe($templates)
        ->and(DB::table('role_translations')->where('locale', $code)->where('role_id', $editor->getKey())->value('label'))->toBe("[{$code}] Editor")
        ->and(DB::table('setting_translations')->where('locale', $code)->count())->toBe(1)
        ->and(DB::table('page_translations')->where('locale', $code)->where('page_id', $pageId)->value('slug'))->not->toBeNull()->not->toBe('about-us')
        ->and(DB::table('team_member_translations')->where('locale', $code)->where('team_member_id', $memberId)->value('slug'))->not->toBeNull()->not->toBe('nadia-haddad');

    $records = AuditRecord::query()->where('action', AuditAction::TRANSLATION_UPDATED)->get();
    expect($records)->toHaveCount($needed)
        ->and($records->every(fn (AuditRecord $record): bool => $record->context['origin'] === 'ai' && $record->context['locale'] === $code))->toBeTrue()
        ->and($records->pluck('subject')->unique())->toHaveCount($needed)
        ->and($records->first(fn (AuditRecord $record): bool => str_starts_with((string) $record->subject, 'notification-templates/'))?->context['fields'])->toBe(['subject', 'body']);

    // ── 7. Status everywhere reads translated; nothing was published ────────
    $after = $this->withToken($token)->getJson("/api/v1/admin/translations?target={$code}&per_page=100")->assertOk();
    $translated = collect($after->json('data.entries'))->filter(fn (array $entry): bool => $entry['status'] === 'translated')->count();

    expect($translated)->toBe($needed)
        ->and(count($after->json('data.entries')))->toBe($items)
        ->and($this->withToken($token)->getJson('/api/v1/admin/pages')->json("data.0.progress.{$code}.complete"))->toBeTrue()
        ->and($this->withToken($token)->getJson('/api/v1/admin/team')->json("data.0.progress.{$code}.complete"))->toBeTrue()
        ->and(collect($this->withToken($token)->getJson('/api/v1/admin/translations/overview')->json('data.languages'))->firstWhere('code', $code)['batches']['accepted'])->toBe($needed)
        ->and(Page::query()->find($pageId)?->status)->toBe(PageStatus::DRAFT)
        ->and(TeamMember::query()->find($memberId)?->is_active)->toBeFalse()
        ->and(Language::query()->where('code', $code)->value('is_active'))->toBeFalse();
});
