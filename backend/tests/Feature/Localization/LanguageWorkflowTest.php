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
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\TranslationSuggestion;
use App\Modules\Notification\Database\Seeders\NotificationTemplateSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Adding a language and translating it, end to end (ADR 0048).
 *
 * The workflow under test: a language is added as a draft the platform does not serve,
 * translated by hand or through AI suggestions a person accepts, its coverage read from
 * the one calculation both screens use — and it is served only when somebody activates
 * it. Every vendor call is faked at the wire.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config(['queue.default' => 'sync']);

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(NotificationTemplateSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    AuditRecord::query()->getQuery()->delete();
});

/**
 * French, added and not served.
 */
function frenchDraft(): Language
{
    $language = Language::query()->create([
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
        'direction' => 'ltr',
        'is_active' => false,
        'is_default' => false,
        'sort_order' => 3,
    ]);

    Cache::flush();

    return $language;
}

/**
 * The seeded editor role, with an English label to translate from.
 */
function editorRole(): Role
{
    /** @var Role $role */
    $role = Role::query()->where('name', 'editor')->sole();
    $role->setTranslation('en', ['label' => 'Editor']);

    return $role->refresh();
}

function aiProviderAnswering(string $answer): void
{
    IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->update(['is_default' => false]);

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
 * A stored suggestion, written directly — the states the filters read.
 */
function storedSuggestion(Role $role, SuggestionStatus $status, string $locale = 'fr'): TranslationSuggestion
{
    return TranslationSuggestion::query()->forceCreate([
        'source_key' => 'roles',
        'item_id' => (string) $role->getKey(),
        'field' => 'label',
        'locale' => $locale,
        'status' => $status,
        'source_text' => 'Editor',
        'suggestion' => $status === SuggestionStatus::PENDING ? null : 'Éditeur',
        'error_code' => $status === SuggestionStatus::FAILED ? 'TIMEOUT' : null,
    ]);
}

function workshopFor(mixed $test, string $token, string $query): mixed
{
    return $test->withToken($token)->getJson('/api/v1/admin/translations?'.$query)->assertOk();
}

function actorOf(string $token): string
{
    return (string) PersonalAccessToken::findToken($token)?->tokenable_id;
}

const EVERY_VIEW = ['settings.view', 'roles.view', 'notifications.view'];

const EVERY_WRITE = ['settings.view', 'settings.update', 'roles.view', 'roles.update', 'notifications.view', 'notifications.update'];

// ── A draft exists and is not served ─────────────────────────────────────────

test('a language can be added as a draft that the platform does not serve', function (): void {
    $token = tokenWithPermissions([]);

    $this->withToken($token)->postJson('/api/v1/admin/languages', [
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
        'direction' => 'ltr',
        'is_active' => false,
    ])->assertCreated()->assertJsonPath('data.is_active', false);

    resetClient($this);

    // Not listed to clients, and not negotiated when asked for by name.
    $public = $this->getJson('/api/v1/languages')->assertOk();
    expect(json_encode($public->json('data')))->not->toContain('"fr"');

    $this->withHeader('X-Locale', 'fr')->getJson('/api/v1/languages')
        ->assertHeader('Content-Language', 'en');
});

test('a draft is a workshop target, with every field missing and nothing counted', function (): void {
    frenchDraft();
    $token = tokenWithPermissions(EVERY_VIEW);

    $response = workshopFor($this, $token, 'target=fr');

    $fr = collect($response->json('data.locales'))->firstWhere('code', 'fr');

    expect($response->json('data.target'))->toBe('fr')
        ->and($response->json('data.source_locale'))->toBe('en')
        ->and($fr['is_active'])->toBeFalse()
        ->and($response->json('data.coverage.total'))->toBeGreaterThan(0)
        ->and($response->json('data.coverage.translated'))->toBe(0);
});

test('a draft is translated by hand before it is served, and coverage moves by one field', function (): void {
    frenchDraft();
    $role = editorRole();
    $token = tokenWithPermissions(EVERY_WRITE);

    $before = workshopFor($this, $token, 'target=fr')->json('data.coverage.translated');

    $this->withToken($token)->putJson('/api/v1/admin/translations/roles/'.$role->getKey(), [
        'locale' => 'fr',
        'values' => ['label' => 'Éditeur'],
    ])->assertOk();

    expect(workshopFor($this, $token, 'target=fr')->json('data.coverage.translated'))->toBe($before + 1)
        // Translated, and still not served: only activation publishes a language.
        ->and(Language::query()->where('code', 'fr')->value('is_active'))->toBeFalse();
});

test('the workshop opens on the first language that is not the default', function (): void {
    $token = tokenWithPermissions(EVERY_VIEW);

    expect(workshopFor($this, $token, '')->json('data.target'))->toBe('ar');
});

test('a target that is not a language is refused', function (): void {
    $token = tokenWithPermissions(EVERY_VIEW);

    $this->withToken($token)->getJson('/api/v1/admin/translations?target=xx')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'UNKNOWN_LOCALE');
});

// ── Filters, search and pages are answered on the server ─────────────────────

test('missing and translated are decided by what has been saved', function (): void {
    frenchDraft();
    $role = editorRole();
    $token = tokenWithPermissions(EVERY_WRITE);

    $this->withToken($token)->putJson('/api/v1/admin/translations/roles/'.$role->getKey(), [
        'locale' => 'fr',
        'values' => ['label' => 'Éditeur'],
    ])->assertOk();

    $translated = array_column(workshopFor($this, $token, 'target=fr&source=roles&state=translated')->json('data.entries'), 'id');
    $missing = array_column(workshopFor($this, $token, 'target=fr&source=roles&state=missing&per_page=100')->json('data.entries'), 'id');

    expect($translated)->toBe([(string) $role->getKey()])
        ->and($missing)->not->toContain((string) $role->getKey())
        ->and($missing)->not->toBe([]);
});

test('needs review and failed come from the suggestion states the platform stores', function (): void {
    frenchDraft();
    $role = editorRole();

    /** @var Role $other */
    $other = Role::query()->where('name', '!=', 'editor')->firstOrFail();
    $other->setTranslation('en', ['label' => 'Other']);

    storedSuggestion($role, SuggestionStatus::READY);
    storedSuggestion($other, SuggestionStatus::FAILED);

    $token = tokenWithPermissions(EVERY_WRITE);

    $review = array_column(workshopFor($this, $token, 'target=fr&state=needs_review')->json('data.entries'), 'id');
    $failed = array_column(workshopFor($this, $token, 'target=fr&state=failed')->json('data.entries'), 'id');

    expect($review)->toBe([(string) $role->getKey()])
        ->and($failed)->toBe([(string) $other->getKey()]);
});

test('search matches the source and the target text', function (): void {
    frenchDraft();
    $role = editorRole();
    $token = tokenWithPermissions(EVERY_WRITE);

    $this->withToken($token)->putJson('/api/v1/admin/translations/roles/'.$role->getKey(), [
        'locale' => 'fr',
        'values' => ['label' => 'Rédacteur en chef'],
    ])->assertOk();

    $byTarget = array_column(workshopFor($this, $token, 'target=fr&search='.urlencode('rédacteur'))->json('data.entries'), 'id');

    expect($byTarget)->toBe([(string) $role->getKey()])
        ->and(workshopFor($this, $token, 'target=fr&search=zzz-nothing-matches')->json('data.pagination.total'))->toBe(0);
});

test('the browser is sent a page, not the catalogue', function (): void {
    frenchDraft();
    $token = tokenWithPermissions(EVERY_VIEW);

    $all = workshopFor($this, $token, 'target=fr&per_page=100')->json('data.pagination.total');
    $page = workshopFor($this, $token, 'target=fr&per_page=2&page=2');

    expect($page->json('data.entries'))->toHaveCount(min(2, max(0, $all - 2)))
        ->and($page->json('data.pagination'))->toBe([
            'page' => 2,
            'per_page' => 2,
            'total' => $all,
            'last_page' => (int) ceil($all / 2),
        ])
        // Past the end is the last page, not an empty one.
        ->and(workshopFor($this, $token, 'target=fr&per_page=2&page=999')->json('data.pagination.page'))
        ->toBe((int) ceil($all / 2));

    $this->withToken($token)->getJson('/api/v1/admin/translations?per_page=500')->assertStatus(422);
});

// ── Coverage and progress on the Languages page ──────────────────────────────

test('the Languages page reads the workshop\'s own coverage', function (): void {
    frenchDraft();
    $role = editorRole();
    $token = tokenWithPermissions(EVERY_WRITE);

    $this->withToken($token)->putJson('/api/v1/admin/translations/roles/'.$role->getKey(), [
        'locale' => 'fr',
        'values' => ['label' => 'Éditeur'],
    ])->assertOk();

    $overview = $this->withToken($token)->getJson('/api/v1/admin/translations/overview')->assertOk();
    $fr = collect($overview->json('data.languages'))->firstWhere('code', 'fr');

    expect($fr['coverage'])->toBe(workshopFor($this, $token, 'target=fr')->json('data.coverage'))
        ->and($overview->json('data.source_locale'))->toBe('en');
});

test('coverage counts only the content the caller may read', function (): void {
    frenchDraft();
    $narrow = tokenWithPermissions(['settings.view']);
    $wide = tokenWithPermissions(EVERY_VIEW);

    $settingsOnly = collect($this->withToken($narrow)->getJson('/api/v1/admin/translations/overview')->json('data.languages'))
        ->firstWhere('code', 'fr')['coverage']['total'];

    resetClient($this);

    $everything = collect($this->withToken($wide)->getJson('/api/v1/admin/translations/overview')->json('data.languages'))
        ->firstWhere('code', 'fr')['coverage']['total'];

    resetClient($this);

    expect($settingsOnly)->toBeLessThan($everything)
        ->and($settingsOnly)->toBe(workshopFor($this, $narrow, 'target=fr')->json('data.coverage.total'));
});

test('AI progress counts the stored states, for content the caller may write', function (): void {
    frenchDraft();
    $role = editorRole();
    storedSuggestion($role, SuggestionStatus::READY);

    $writer = tokenWithPermissions(['roles.view', 'roles.update']);
    $reader = tokenWithPermissions(['roles.view']);

    $counts = fn (string $token): array => collect(
        $this->withToken($token)->getJson('/api/v1/admin/translations/overview')->json('data.languages')
    )->firstWhere('code', 'fr')['suggestions'];

    expect($counts($writer))->toBe(['pending' => 0, 'ready' => 1, 'failed' => 0, 'accepted' => 0, 'dismissed' => 0]);

    resetClient($this);

    // A suggestion carries its source text; one for content the caller cannot write is
    // not theirs to count.
    expect($counts($reader)['ready'])->toBe(0);
});

test('the overview says whether AI is there and whether the caller may use it', function (): void {
    $token = tokenWithPermissions(['ai.use']);

    $this->withToken($token)->getJson('/api/v1/admin/translations/overview')
        ->assertJsonPath('data.ai.available', false)
        ->assertJsonPath('data.ai.may_use', true);

    aiProviderAnswering('x');
    resetClient($this);

    $this->withToken(tokenWithPermissions([]))->getJson('/api/v1/admin/translations/overview')
        ->assertJsonPath('data.ai.available', true)
        ->assertJsonPath('data.ai.may_use', false);
});

// ── AI on a draft: proposed, reviewed, accepted ──────────────────────────────

test('AI fills a draft with suggestions and writes nothing', function (): void {
    frenchDraft();
    $role = editorRole();
    aiProviderAnswering('Éditeur');

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);

    $this->withToken($token)->postJson('/api/v1/admin/translations/suggestions', [
        'locale' => 'fr',
        'source' => 'roles',
        'item' => (string) $role->getKey(),
    ])->assertOk()->assertJsonPath('data.queued', 1);

    $suggestion = TranslationSuggestion::query()->sole();

    expect($suggestion->status)->toBe(SuggestionStatus::READY)
        ->and($suggestion->suggestion)->toBe('Éditeur')
        ->and($role->refresh()->translations->firstWhere('locale', 'fr'))->toBeNull()
        // Generated is not translated.
        ->and(workshopFor($this, $token, 'target=fr&source=roles&state=translated')->json('data.entries'))->toBe([]);
});

test('accepting records who accepted, and an AI translation in the trail without its text', function (): void {
    frenchDraft();
    $role = editorRole();
    aiProviderAnswering('Éditeur');

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);

    $this->withToken($token)->postJson('/api/v1/admin/translations/suggestions', [
        'locale' => 'fr', 'source' => 'roles', 'item' => (string) $role->getKey(),
    ])->assertOk();

    $suggestion = TranslationSuggestion::query()->sole();

    $this->withToken($token)
        ->postJson("/api/v1/admin/translations/suggestions/{$suggestion->id}/accept", ['text' => 'Éditrice'])
        ->assertOk()
        ->assertJsonPath('data.edited', true);

    $record = AuditRecord::query()->where('action', AuditAction::TRANSLATION_UPDATED)->sole();

    expect($suggestion->refresh()->accepted_by)->toBe(actorOf($token))
        ->and($record->actor_id)->toBe(actorOf($token))
        ->and($record->subject)->toBe('roles/'.$role->getKey())
        ->and($record->context)->toBe([
            'source' => 'roles',
            'item' => (string) $role->getKey(),
            'locale' => 'fr',
            'fields' => ['label'],
            'origin' => 'ai',
            'edited' => true,
        ])
        ->and(json_encode($record->context))->not->toContain('Éditri')
        ->and($role->refresh()->translate('label', 'fr'))->toBe('Éditrice');
});

test('a manual save is recorded without its text, and a save that changes nothing is not', function (): void {
    frenchDraft();
    $role = editorRole();
    $token = tokenWithPermissions(EVERY_WRITE);

    $save = fn () => $this->withToken($token)->putJson('/api/v1/admin/translations/roles/'.$role->getKey(), [
        'locale' => 'fr',
        'values' => ['label' => 'Éditeur'],
    ])->assertOk();

    $save();
    $save();

    $records = AuditRecord::query()->where('action', AuditAction::TRANSLATION_UPDATED)->get();

    expect($records)->toHaveCount(1)
        ->and($records[0]->context)->toBe([
            'source' => 'roles',
            'item' => (string) $role->getKey(),
            'locale' => 'fr',
            'fields' => ['label'],
            'origin' => 'manual',
        ]);
});

test('a failed suggestion can be asked for again, and the existing translation is untouched', function (): void {
    frenchDraft();
    $role = editorRole();
    $role->setTranslation('fr', ['label' => 'Écrit à la main']);

    storedSuggestion($role, SuggestionStatus::FAILED);
    aiProviderAnswering('Éditeur');

    $token = tokenWithPermissions(['roles.view', 'roles.update', 'ai.use']);

    // Asked for explicitly, because the field already has text.
    $this->withToken($token)->postJson('/api/v1/admin/translations/suggestions', [
        'locale' => 'fr', 'source' => 'roles', 'item' => (string) $role->getKey(), 'include_translated' => true,
    ])->assertOk()->assertJsonPath('data.queued', 1);

    $suggestion = TranslationSuggestion::query()->sole();

    expect($suggestion->status)->toBe(SuggestionStatus::READY)
        ->and($suggestion->error_code)->toBeNull()
        ->and($role->refresh()->translate('label', 'fr'))->toBe('Écrit à la main');
});

test('the default language still cannot be switched off', function (): void {
    $token = tokenWithPermissions([]);
    $english = Language::query()->where('code', 'en')->sole();

    $this->withToken($token)->patchJson("/api/v1/admin/languages/{$english->id}/status")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CANNOT_DEACTIVATE_DEFAULT_LANGUAGE');
});

test('every translation action resolves a label in both locales', function (): void {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        expect(__('audit.action.'.AuditAction::TRANSLATION_UPDATED))
            ->not->toBe('audit.action.'.AuditAction::TRANSLATION_UPDATED);
    }
});
