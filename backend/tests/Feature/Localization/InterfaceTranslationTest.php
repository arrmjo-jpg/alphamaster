<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Enums\BatchStatus;
use App\Modules\Localization\Interface\InterfaceCatalogue;
use App\Modules\Localization\Models\InterfaceTranslation;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\TranslationBatch;
use App\Modules\Localization\Models\TranslationSuggestion;
use App\Modules\Localization\Services\PlaceholderFidelity;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config(['queue.default' => 'sync']);

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    Language::query()->update(['is_default' => false]);
    Language::query()->where('code', 'en')->update(['is_default' => true, 'is_active' => true]);
    Cache::flush();
});

afterEach(function (): void {
    if (isset($this->deployedLang)) {
        File::deleteDirectory($this->deployedLang);
    }
});

// The platform's interface in any language Language Management knows (ADR 0049): the console
// catalogue and the API's messages, translated through the same workshop as content, served at
// runtime, with nothing written in code per language and nothing registered per key.

function interfaceToken(): string
{
    return tokenWithPermissions(['interface.translate', 'ai.use']);
}

/** A language no code has heard of, added the way an operator adds one. */
function languageNobodyCodedFor(mixed $test, string $direction = 'rtl'): string
{
    $code = 'q'.Str::lower(Str::random(3));

    $test->withToken(tokenWithPermissions(['languages.manage']))->postJson('/api/v1/admin/languages', [
        'code' => $code, 'name' => 'Invented', 'native_name' => 'Erfunden', 'direction' => $direction, 'is_active' => true,
    ])->assertCreated();

    resetClient($test);

    return $code;
}

/** A provider that answers every key with the key's own text, marked with the language. */
function interfaceProviderTranslatingInto(string $code, ?Closure $answer = null): void
{
    IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->update(['is_default' => false]);

    /** @var IntegrationProvider $provider */
    $provider = IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->where('driver', 'openai')->firstOrFail();
    $provider->setCredentials(['api_key' => 'sk-not-a-real-key']);
    $provider->forceFill(['is_active' => true, 'is_default' => true])->save();

    Http::fake(['api.openai.com/*' => function (HttpRequest $request) use ($code, $answer) {
        $messages = $request->data()['messages'];
        $text = (string) end($messages)['content'];

        return Http::response([
            'choices' => [['message' => ['content' => $answer === null ? "[{$code}] {$text}" : $answer($text)]]],
            'usage' => ['total_tokens' => 9],
        ]);
    }]);
}

/** @return array<string, string> */
function consoleSource(): array
{
    return app(InterfaceCatalogue::class)->source(InterfaceCatalogue::CONSOLE);
}

/** @return list<string> */
function consoleItems(): array
{
    $items = [];

    foreach (array_keys(consoleSource()) as $key) {
        $parent = strrpos($key, '.');
        $items[$parent === false ? $key : substr($key, 0, $parent)] = true;
    }

    return array_keys($items);
}

// ── 1. A language is a whole language, at runtime ────────────────────────────

test('a language added in Language Management is offered to the console and served its wording, with no code change', function (): void {
    $code = languageNobodyCodedFor($this);

    $public = collect($this->getJson('/api/v1/languages')->assertOk()->json('data'))->firstWhere('code', $code);

    expect($public)->not->toBeNull()
        ->and($public['direction'])->toBe('rtl');

    // Nothing translated yet: nothing served, and the console reads the source key by key.
    expect($this->getJson("/api/v1/interface/console/{$code}")->assertOk()->json('data'))->toBe([]);

    $this->getJson('/api/v1/interface/console/zz9')->assertNotFound()->assertJsonPath('error.code', 'UNKNOWN_LOCALE');

    // Arabic keeps what ships for it.
    $shippedArabic = Arr::dot(json_decode((string) file_get_contents(lang_path('interface/console/ar.json')), true));
    expect($this->getJson('/api/v1/interface/console/ar')->assertOk()->json('data.modules.dashboard'))->toBe($shippedArabic['modules.dashboard']);
});

test('every interface key is in the workshop for a new language, as not translated, in items derived from the keys', function (): void {
    $code = languageNobodyCodedFor($this);

    $response = $this->withToken(interfaceToken())->getJson("/api/v1/admin/translations?target={$code}&source=interface-console&per_page=100")->assertOk();

    $sources = collect($response->json('data.sources'))->keyBy('key');
    $modules = collect($response->json('data.entries'))->firstWhere('id', 'modules');

    expect($sources->keys()->all())->toContain('interface-console', 'interface-api')
        ->and($sources['interface-console']['source_locale'])->toBe('en')
        ->and($sources['interface-console']['completeness'])->toBe(['total' => count(consoleItems()), 'translated' => 0])
        ->and($sources['interface-console']['statuses']['not_translated'])->toBe(count(consoleItems()))
        ->and($modules['status'])->toBe('not_translated')
        ->and(array_column($modules['fields'], 'name'))->toContain('modules.dashboard', 'modules.translations')
        ->and(collect($modules['fields'])->firstWhere('name', 'modules.dashboard')['values'])->toBe(['en' => consoleSource()['modules.dashboard']]);

    // Arabic is shipped, so it is translated there.
    $arabic = collect($this->withToken(interfaceToken())->getJson('/api/v1/admin/translations?target=ar&source=interface-console&per_page=100')->json('data.entries'))->firstWhere('id', 'modules');
    expect($arabic['status'])->toBe('translated');
});

test('the interface is not offered to an operator without the permission to translate it', function (): void {
    $keys = array_column($this->withToken(tokenWithPermissions(['settings.view']))->getJson('/api/v1/admin/translations')->assertOk()->json('data.sources'), 'key');

    expect($keys)->not->toContain('interface-console')->not->toContain('interface-api');
});

// ── 2. Translate all missing, review, accept once, and the console reads it ──

test('translate all missing translates the whole console, each item is accepted once, and the console is served the translation', function (): void {
    $code = languageNobodyCodedFor($this);
    interfaceProviderTranslatingInto($code);

    $token = interfaceToken();
    $items = count(consoleItems());

    $this->withToken($token)->postJson('/api/v1/admin/translations/batches', ['locale' => $code, 'source' => 'interface-console'])
        ->assertOk()
        ->assertJsonPath('data.queued', $items);

    $calls = count(Http::recorded());

    // Asked again: nothing new, nothing billed.
    $this->withToken($token)->postJson('/api/v1/admin/translations/batches', ['locale' => $code, 'source' => 'interface-console'])
        ->assertOk()
        ->assertJsonPath('data.existing', $items);

    expect(count(Http::recorded()))->toBe($calls)
        ->and($calls)->toBe(count(consoleSource()))
        ->and(TranslationBatch::query()->where('status', BatchStatus::READY->value)->count())->toBe($items)
        ->and(InterfaceTranslation::query()->count())->toBe(0);

    // Every request was translated from the catalogue's English, not the content default.
    $sample = collect(Http::recorded())->first()[0];
    expect($sample->data()['messages'][0]['content'])->toContain('Source language: English');

    $this->withToken($token)->postJson('/api/v1/admin/translations/batches/accept-ready', ['locale' => $code, 'source' => 'interface-console'])
        ->assertOk()
        ->assertJsonPath('data.accepted', $items)
        ->assertJsonPath('data.failed', 0);

    $served = $this->getJson("/api/v1/interface/console/{$code}")->assertOk();

    expect($served->json('data.modules.dashboard'))->toBe("[{$code}] ".consoleSource()['modules.dashboard'])
        ->and($served->json('data.modules.translations'))->toBe("[{$code}] ".consoleSource()['modules.translations'])
        ->and(InterfaceTranslation::query()->where('locale', $code)->count())->toBe(count(consoleSource()));

    $sources = collect($this->withToken($token)->getJson("/api/v1/admin/translations?target={$code}&source=interface-console")->json('data.sources'))->keyBy('key');
    expect($sources['interface-console']['statuses']['translated'])->toBe($items);
});

test('a key a developer adds to the English catalogue is in every language as not translated after deploy, and is translated like any other', function (): void {
    $code = languageNobodyCodedFor($this);
    $token = interfaceToken();

    // The deploy: the same catalogue, with one more key in its English file.
    $this->deployedLang = storage_path('framework/testing/lang-'.Str::random(8));
    File::copyDirectory(lang_path(), $this->deployedLang);

    $english = json_decode((string) file_get_contents($this->deployedLang.'/interface/console/en.json'), true);
    $english['navigation']['test_feature'] = 'Test feature';
    file_put_contents($this->deployedLang.'/interface/console/en.json', json_encode($english, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    app()->useLangPath($this->deployedLang);

    foreach ([$code, 'ar'] as $locale) {
        $entry = collect($this->withToken($token)->getJson("/api/v1/admin/translations?target={$locale}&source=interface-console&search=test+feature")->assertOk()->json('data.entries'))->firstWhere('id', 'navigation');

        expect($entry)->not->toBeNull()
            ->and(array_column($entry['fields'], 'name'))->toContain('navigation.test_feature')
            ->and($entry['status'])->toBe('not_translated');
    }

    interfaceProviderTranslatingInto($code);

    $this->withToken($token)->postJson('/api/v1/admin/translations/batches', ['locale' => $code, 'source' => 'interface-console'])->assertOk();

    $batch = TranslationBatch::query()->where('item_id', 'navigation')->sole();
    $this->withToken($token)->postJson("/api/v1/admin/translations/batches/{$batch->getKey()}/accept")->assertOk();

    expect($this->getJson("/api/v1/interface/console/{$code}")->json('data.navigation.test_feature'))->toBe("[{$code}] Test feature");
});

// ── 3. What is written, and what survives ────────────────────────────────────

test('an operator’s change to shipped Arabic is served over it, and the rest of Arabic is untouched', function (): void {
    $token = interfaceToken();
    $before = $this->getJson('/api/v1/interface/console/ar')->assertOk()->json('data');

    $this->withToken($token)->putJson('/api/v1/admin/translations/interface-console/modules', [
        'locale' => 'ar',
        'values' => ['modules.dashboard' => 'لوحة القيادة'],
    ])->assertOk();

    $after = $this->getJson('/api/v1/interface/console/ar')->assertOk()->json('data');

    expect($after['modules']['dashboard'])->toBe('لوحة القيادة')
        ->and(Arr::except(Arr::dot($after), 'modules.dashboard'))->toBe(Arr::except(Arr::dot($before), 'modules.dashboard'))
        ->and(InterfaceTranslation::query()->count())->toBe(1);

    // Taking it back returns the shipped wording.
    $this->withToken($token)->putJson('/api/v1/admin/translations/interface-console/modules', [
        'locale' => 'ar',
        'values' => ['modules.dashboard' => null],
    ])->assertOk();

    expect($this->getJson('/api/v1/interface/console/ar')->json('data.modules.dashboard'))->toBe($before['modules']['dashboard']);
});

test('a translation written against English that has since changed is still shown, and counted as not translated', function (): void {
    $code = languageNobodyCodedFor($this);

    InterfaceTranslation::query()->create([
        'catalogue' => 'console', 'locale' => $code, 'key' => 'modules.dashboard',
        'value' => 'Alt', 'source_hash' => InterfaceCatalogue::hash('What the English used to say'),
    ]);
    app(InterfaceCatalogue::class)->forget();

    expect($this->getJson("/api/v1/interface/console/{$code}")->json('data.modules.dashboard'))->toBe('Alt');

    $modules = collect($this->withToken(interfaceToken())->getJson("/api/v1/admin/translations?target={$code}&source=interface-console&per_page=100")->json('data.entries'))->firstWhere('id', 'modules');
    $field = collect($modules['fields'])->firstWhere('name', 'modules.dashboard');

    expect($field['values'])->not->toHaveKey($code);
});

test('the interface’s own language is changed in code, not in the workshop', function (): void {
    $this->withToken(interfaceToken())->putJson('/api/v1/admin/translations/interface-console/modules', [
        'locale' => 'en',
        'values' => ['modules.dashboard' => 'Home'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'TRANSLATION_REFUSED');

    expect(InterfaceTranslation::query()->count())->toBe(0);
});

// ── 4. Placeholders and plural forms ─────────────────────────────────────────

test('a translation that loses or invents a placeholder is refused, by a person or by the model', function (): void {
    $code = languageNobodyCodedFor($this);
    $token = interfaceToken();

    $withPlaceholder = collect(consoleSource())->filter(fn (string $text): bool => str_contains($text, '{{'))->keys()->first();
    $item = substr($withPlaceholder, 0, strrpos($withPlaceholder, '.'));

    $this->withToken($token)->putJson("/api/v1/admin/translations/interface-console/{$item}", [
        'locale' => $code,
        'values' => [$withPlaceholder => 'No placeholder here'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'TRANSLATION_REFUSED');

    // The model drops every placeholder.
    interfaceProviderTranslatingInto($code, fn (string $text): string => (string) preg_replace('/\{\{[^}]+\}\}/', '', $text));

    $this->withToken($token)->postJson('/api/v1/admin/translations/batches', ['locale' => $code, 'source' => 'interface-console', 'item' => $item])->assertOk();

    $field = TranslationSuggestion::query()->where('item_id', $item)->where('field', $withPlaceholder)->sole();

    expect($field->error_code)->toBe('PLACEHOLDERS_CHANGED')
        ->and(TranslationBatch::query()->where('item_id', $item)->sole()->status)->toBe(BatchStatus::FAILED);
});

test('placeholder fidelity allows words to move and refuses a changed set', function (): void {
    expect(PlaceholderFidelity::preserved('{{count}} items for {{name}}', 'لـ {{name}}: {{count}} عناصر'))->toBeTrue()
        ->and(PlaceholderFidelity::preserved('Hello :name', 'مرحبًا :name'))->toBeTrue()
        ->and(PlaceholderFidelity::preserved('{0} None|[1,*] :count items', '{0} لا شيء|[1,*] :count عناصر'))->toBeTrue()
        ->and(PlaceholderFidelity::preserved('Hello :name', 'مرحبًا'))->toBeFalse()
        ->and(PlaceholderFidelity::preserved('Hello :name', 'مرحبًا :email'))->toBeFalse()
        ->and(PlaceholderFidelity::preserved('{{count}} items', '{{total}} عناصر'))->toBeFalse()
        ->and(PlaceholderFidelity::preserved('{0} None|[1,*] :count items', ':count عناصر'))->toBeFalse()
        ->and(PlaceholderFidelity::preserved('See https://example.test at 10:30', 'انظر https://example.test الساعة 10:30'))->toBeTrue();
});

// ── 5. The API's messages, at runtime ────────────────────────────────────────

test('API and validation messages are served in the language from what operators translated, with no file for it', function (): void {
    $code = languageNobodyCodedFor($this);
    $token = interfaceToken();

    $this->withToken($token)->putJson('/api/v1/admin/translations/interface-api/api.translations', [
        'locale' => $code,
        'values' => ['api.translations.written' => "[{$code}] saved"],
    ])->assertOk();

    $this->withToken($token)->putJson('/api/v1/admin/translations/interface-api/validation', [
        'locale' => $code,
        'values' => ['validation::required' => "[{$code}] :attribute is needed"],
    ])->assertOk();

    resetClient($this);

    // A JSON line, through the same `__()` every message uses.
    $writer = tokenWithPermissions(['interface.translate']);
    $this->withToken($writer)
        ->withHeader('X-Locale', $code)
        ->putJson('/api/v1/admin/translations/interface-api/api.translations', [
            'locale' => $code,
            'values' => ['api.translations.written' => "[{$code}] saved"],
        ])
        ->assertOk()
        ->assertHeader('Content-Language', $code)
        ->assertJsonPath('message', "[{$code}] saved");

    resetClient($this);

    // A group line: Laravel's validator, unchanged.
    $validation = $this->withHeader('X-Locale', $code)->postJson('/api/v1/auth/login', [])->assertStatus(422);

    expect(json_encode($validation->json()))->toContain("[{$code}]");
});
