<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Ai\ErrorRedactor;
use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Ai\TextGeneratorContract;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Setting up AI providers: provider, API key, model, test, save.
 *
 * Each provider keeps its own key and model, several can be configured at once, and one
 * is the default. A provider is ready when it holds a key; there is no other switch. The
 * model always belongs to the provider it is sent to, and the endpoint always belongs to
 * the driver. The key is written, never read back, and never recorded. Every vendor
 * call is faked at the wire.
 */
uses(RefreshDatabase::class);

const CONFIGURE = ['integrations.view', 'integrations.update', 'ai.use'];

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);

    AuditRecord::query()->getQuery()->delete();
});

function aiRow(string $driver): IntegrationProvider
{
    return IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->where('driver', $driver)
        ->firstOrFail();
}

function setUpProvider(mixed $test, string $token, string $driver, array $body): mixed
{
    return $test->withToken($token)->putJson("/api/v1/admin/ai/providers/{$driver}", $body);
}

function fakeVendors(): void
{
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'OK']]],
            'usage' => ['total_tokens' => 4],
        ]),
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'OK']],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 1],
        ]),
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'OK']]]]],
            'usageMetadata' => ['totalTokenCount' => 5],
        ]),
    ]);
}

// ── What is offered ──────────────────────────────────────────────────────────

test('three providers are offered, each with its own models and none with a key', function (): void {
    $response = $this->withToken(tokenWithPermissions(['integrations.view']))
        ->getJson('/api/v1/admin/ai')
        ->assertOk();

    $providers = collect($response->json('data.providers'))->keyBy('driver');

    expect($providers->keys()->all())->toBe(['openai', 'anthropic', 'gemini'])
        ->and($providers['openai']['default_model'])->toBe('gpt-5.6-luna')
        ->and($providers['anthropic']['default_model'])->toBe('claude-sonnet-5')
        ->and($providers['gemini']['default_model'])->toBe('gemini-3.5-flash-lite')
        ->and($providers['gemini']['label'])->toBe('Google Gemini')
        ->and($providers->pluck('has_key')->all())->toBe([false, false, false]);

    foreach ($providers as $provider) {
        // A provider's suggestions are its own, and include its default.
        expect($provider['suggested_models'])->toContain($provider['default_model']);
    }

    expect($providers['anthropic']['suggested_models'])->not->toContain('gpt-5.6-luna');
});

// ── Saving ───────────────────────────────────────────────────────────────────

test('saving stores the key encrypted and the model, and never returns the key', function (): void {
    $response = setUpProvider($this, tokenWithPermissions(CONFIGURE), 'openai', [
        'api_key' => 'sk-proj-looks-real-000111',
        'model' => 'gpt-5.6-terra',
    ])->assertOk();

    $row = aiRow('openai');

    expect($response->json('data.has_key'))->toBeTrue()
        ->and($row->is_active)->toBeTrue()
        ->and($response->json('data'))->not->toHaveKey('enabled')
        ->and($response->json('data.model'))->toBe('gpt-5.6-terra')
        ->and($response->content())->not->toContain('sk-proj-looks-real-000111')
        ->and((string) $row->getRawOriginal('credentials'))->not->toContain('sk-proj-looks-real-000111')
        ->and($row->getCredentials())->toBe(['api_key' => 'sk-proj-looks-real-000111']);
});

test('the first provider saved becomes the default, and the next one does not take it', function (): void {
    $token = tokenWithPermissions(CONFIGURE);

    setUpProvider($this, $token, 'anthropic', ['api_key' => 'sk-ant-1', 'model' => 'claude-sonnet-5'])
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    setUpProvider($this, $token, 'openai', ['api_key' => 'sk-1', 'model' => 'gpt-5.6-luna'])
        ->assertOk()
        ->assertJsonPath('data.is_default', false);

    expect(aiRow('anthropic')->is_default)->toBeTrue();
});

test('configuring one provider never disables another', function (): void {
    $token = tokenWithPermissions(CONFIGURE);

    setUpProvider($this, $token, 'openai', ['api_key' => 'sk-1', 'model' => 'gpt-5.6-luna'])->assertOk();
    setUpProvider($this, $token, 'anthropic', ['api_key' => 'sk-ant-1', 'model' => 'claude-haiku-4-5'])->assertOk();
    setUpProvider($this, $token, 'gemini', ['api_key' => 'AIza-1', 'model' => 'gemini-2.5-pro'])->assertOk();

    $rows = IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->get();

    expect($rows->every(fn (IntegrationProvider $row): bool => $row->is_active && $row->hasCredentials()))->toBeTrue()
        ->and($rows->where('is_default', true))->toHaveCount(1)
        // Each keeps its own model.
        ->and(aiRow('openai')->settings['model'])->toBe('gpt-5.6-luna')
        ->and(aiRow('anthropic')->settings['model'])->toBe('claude-haiku-4-5')
        ->and(aiRow('gemini')->settings['model'])->toBe('gemini-2.5-pro');
});

test('a provider cannot be saved without a key, and a stored key survives a save without one', function (): void {
    $token = tokenWithPermissions(CONFIGURE);

    setUpProvider($this, $token, 'gemini', ['model' => 'gemini-3.5-flash-lite'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'AI_KEY_REQUIRED');

    setUpProvider($this, $token, 'gemini', ['api_key' => 'AIza-kept', 'model' => 'gemini-3.5-flash-lite'])->assertOk();
    $ciphertext = aiRow('gemini')->getRawOriginal('credentials');

    setUpProvider($this, $token, 'gemini', ['model' => 'gemini-2.5-pro'])->assertOk();

    expect(aiRow('gemini')->getRawOriginal('credentials'))->toBe($ciphertext)
        ->and(aiRow('gemini')->settings['model'])->toBe('gemini-2.5-pro');
});

test('saving writes the model and nothing about how the driver reaches its vendor', function (): void {
    foreach (['openai', 'anthropic', 'gemini'] as $driver) {
        // Shipped with nothing to edit: the endpoint is the driver's.
        expect(aiRow($driver)->settings)->toBeNull();
    }

    // Anything else in the body is not a field the setup has, and is not stored.
    setUpProvider($this, tokenWithPermissions(CONFIGURE), 'gemini', [
        'api_key' => 'AIza-1',
        'model' => 'gemini-2.5-pro',
        'base_url' => 'https://gateway.example.test/v1beta',
        'enabled' => false,
    ])->assertOk();

    expect(aiRow('gemini')->settings)->toBe(['model' => 'gemini-2.5-pro'])
        ->and(aiRow('gemini')->is_active)->toBeTrue()
        ->and(aiRow('gemini')->getCredentials())->toBe(['api_key' => 'AIza-1']);
});

test('the setup the Admin reads names no driver internals', function (): void {
    setUpProvider($this, tokenWithPermissions(CONFIGURE), 'openai', ['api_key' => 'sk-1', 'model' => 'gpt-5.6-luna'])->assertOk();

    resetClient($this);

    $body = $this->withToken(tokenWithPermissions(['integrations.view']))
        ->getJson('/api/v1/admin/ai')
        ->assertOk()
        ->content();

    expect($body)->not->toContain('base_url')
        ->not->toContain('api_key')
        ->not->toContain('"enabled"')
        ->not->toContain('priority');
});

test('a model has to look like a model', function (): void {
    setUpProvider($this, tokenWithPermissions(CONFIGURE), 'openai', ['api_key' => 'sk-1', 'model' => 'not a model'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('an unknown provider is refused', function (): void {
    setUpProvider($this, tokenWithPermissions(CONFIGURE), 'mistral', ['api_key' => 'x', 'model' => 'm'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'AI_PROVIDER_UNKNOWN');
});

test('setting up a provider needs the permission that changes integrations', function (): void {
    setUpProvider($this, tokenWithPermissions(['integrations.view', 'ai.use']), 'openai', ['api_key' => 'sk-1', 'model' => 'gpt-5.6-luna'])
        ->assertForbidden();

    expect(aiRow('openai')->hasCredentials())->toBeFalse();
});

// ── The model belongs to the provider ────────────────────────────────────────

test('the answering provider sends its own saved model', function (): void {
    fakeVendors();
    setUpProvider($this, tokenWithPermissions(CONFIGURE), 'anthropic', ['api_key' => 'sk-ant-1', 'model' => 'claude-haiku-4-5'])->assertOk();

    app(TextGeneratorContract::class)->generate(new TextGenerationRequest(instruction: 'Translate.', content: 'Save'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.anthropic.com')
        && $request->data()['model'] === 'claude-haiku-4-5');
});

test('a provider with no saved model uses its own default, never another vendor’s', function (): void {
    fakeVendors();

    IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->update(['is_default' => false]);
    $gemini = aiRow('gemini');
    $gemini->setCredentials(['api_key' => 'AIza-1']);
    $gemini->forceFill(['is_active' => true, 'is_default' => true])->save();

    app(TextGeneratorContract::class)->generate(new TextGenerationRequest(instruction: 'Translate.', content: 'Save'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'models/gemini-3.5-flash-lite:generateContent'));
});

// ── The endpoint belongs to the driver ───────────────────────────────────────

test('each provider is called at its vendor’s own address, and a stored address is ignored', function (string $driver, string $url, string $model): void {
    Http::preventStrayRequests();
    fakeVendors();

    IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->update(['is_default' => false]);
    $row = aiRow($driver);
    $row->setCredentials(['api_key' => 'key-1']);
    // What an old installation could hold: an address an operator was once invited to
    // type. It is not configuration any more, so it changes nothing.
    $row->forceFill([
        'settings' => ['base_url' => 'https://gateway.example.test/v1', 'model' => $model],
        'is_active' => true,
        'is_default' => true,
    ])->save();

    $result = app(TextGeneratorContract::class)->generate(new TextGenerationRequest(instruction: 'Translate.', content: 'Save'));

    expect($result->successful)->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === $url);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'gateway.example.test'));
})->with([
    'OpenAI' => ['openai', 'https://api.openai.com/v1/chat/completions', 'gpt-5.6-luna'],
    'Anthropic' => ['anthropic', 'https://api.anthropic.com/v1/messages', 'claude-sonnet-5'],
    'Google Gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent', 'gemini-3.5-flash-lite'],
]);

// ── Vendor errors are cleaned before they are kept or shown ─────────────────
//
// Keys here are assembled from parts so that the repository's secret scan, which
// rightly flags a key-shaped literal, does not mistake a fixture for a leak.

test('the redactor keeps the diagnosis and removes every shape a secret takes', function (): void {
    $key = implode('-', ['sk', 'ant', 'api03', str_repeat('Ab3', 12)]);
    $google = 'AIza'.str_repeat('Sy9', 10);
    $token = str_repeat('a1', 20);

    $clean = ErrorRedactor::message(
        "Refused {$key} with Authorization: Bearer abc.def.ghi, x-api-key: {$key}, "
        ."url https://example.test/v1?key={$google}&alt=json and token {$token}",
        [$key]
    );

    expect($clean)->toContain('Refused')
        ->toContain('Bearer [redacted]')
        ->toContain('key=[redacted]')
        ->not->toContain($key)
        ->not->toContain('abc.def.ghi')
        ->not->toContain($google)
        ->not->toContain($token);
});

test('a raw body, a private key and an overlong message are not kept as they came', function (): void {
    // A PEM block of realistic shape — four 64-character lines between the armour —
    // whose body is plainly not key material. The armour is assembled from parts, like
    // the keys above, so the repository's secret scan does not read the fixture as a
    // committed key.
    $armour = static fn (string $edge): string => "-----{$edge} ".'PRIVATE KEY-----';
    $pem = implode("\n", [$armour('BEGIN'), ...array_fill(0, 4, str_repeat('FAKE', 16)), $armour('END')]);

    expect(ErrorRedactor::message('{"error":{"message":"boom","api_key":"x"}}'))->toBe('[body omitted]')
        ->and(ErrorRedactor::message($pem))->toBe('[redacted]')
        ->and(ErrorRedactor::message("Could not sign the assertion with {$pem}"))->toBe('Could not sign the assertion with [redacted]')
        ->and(ErrorRedactor::message('<html><body>Bad gateway</body></html>'))->toBe('Bad gateway')
        ->and(mb_strlen(ErrorRedactor::message(str_repeat('word ', 200))))->toBe(300)
        ->and(ErrorRedactor::message(''))->toBe('The provider returned an error.')
        // An ordinary vendor diagnosis passes through untouched.
        ->and(ErrorRedactor::message('No such model.'))->toBe('No such model.');
});

test('an error code is an identifier, or a generic one', function (): void {
    expect(ErrorRedactor::code('invalid_api_key'))->toBe('invalid_api_key')
        ->and(ErrorRedactor::code('401'))->toBe('401')
        ->and(ErrorRedactor::code('INVALID_ARGUMENT'))->toBe('INVALID_ARGUMENT')
        ->and(ErrorRedactor::code('a code with spaces'))->toBe('PROVIDER_ERROR')
        ->and(ErrorRedactor::code(null))->toBe('PROVIDER_ERROR')
        ->and(ErrorRedactor::code('sk-'.str_repeat('x9', 10)))->toBe('PROVIDER_ERROR');
});

test('a vendor that quotes the stored key back is recorded without it', function (string $driver, string $url, array $error, int $status): void {
    $key = implode('-', ['key', 'for', $driver, str_repeat('Q7w', 8)]);

    IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->update(['is_default' => false]);
    $row = aiRow($driver);
    $row->setCredentials(['api_key' => $key]);
    $row->forceFill(['is_active' => true, 'is_default' => true])->save();

    Http::fake([$url => Http::response([
        'error' => array_map(fn (string $value): string => str_replace('{key}', $key, $value), $error),
    ], $status)]);

    $result = app(TextGeneratorContract::class)->generate(new TextGenerationRequest(instruction: 'x', content: 'y'));
    $logged = IntegrationUsageLog::query()->sole();

    expect($result->successful)->toBeFalse()
        ->and($result->errorMessage)->toContain('[redacted]');

    foreach ([(string) $result->errorMessage, (string) $logged->error_message, (string) $logged->error_code] as $text) {
        expect($text)->not->toContain($key)
            ->not->toContain(substr($key, 0, 8))
            ->not->toContain(substr($key, -6));
    }
})->with([
    'OpenAI' => ['openai', 'api.openai.com/*', ['code' => 'invalid_api_key', 'message' => 'Incorrect API key provided: {key}.'], 401],
    'Anthropic' => ['anthropic', 'api.anthropic.com/*', ['type' => 'authentication_error', 'message' => 'invalid x-api-key {key}'], 401],
    'Google Gemini' => ['gemini', 'generativelanguage.googleapis.com/*', ['status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid: {key}'], 400],
]);

test('an unsaved key the vendor quotes back is never shown in the Admin', function (): void {
    $typed = implode('-', ['sk', 'proj', str_repeat('Vx4', 12)]);

    // OpenAI's own habit: the first characters and the last four around a mask — and,
    // for good measure, the whole key in a quoted header.
    Http::fake(['api.openai.com/*' => Http::response(['error' => [
        'code' => 'invalid_api_key',
        'message' => 'Incorrect API key provided: '.substr($typed, 0, 8).str_repeat('*', 24).substr($typed, -4)
            .'. Sent as Authorization: Bearer '.$typed.'.',
    ]], 401)]);

    $token = tokenWithPermissions(CONFIGURE);

    $check = $this->withToken($token)->postJson('/api/v1/admin/ai/check', [
        'provider' => 'openai',
        'api_key' => $typed,
        'model' => 'gpt-5.6-luna',
    ])
        ->assertOk()
        ->assertJsonPath('data.answered', false)
        ->assertJsonPath('data.error_code', 'invalid_api_key');

    // The diagnosis survives; the key does not.
    expect($check->json('data.error_message'))->toStartWith('Incorrect API key provided: [redacted]');

    $shown = [
        $check->content(),
        $this->withToken($token)->getJson('/api/v1/admin/ai')->assertOk()->content(),
        $this->withToken($token)->getJson('/api/v1/admin/integrations/usage')->assertOk()->content(),
        (string) IntegrationUsageLog::query()->sole()->error_message,
    ];

    foreach ($shown as $text) {
        expect($text)->not->toContain($typed)
            ->not->toContain(substr($typed, -4))
            ->not->toContain('****');
    }

    expect(aiRow('openai')->hasCredentials())->toBeFalse();
});

// ── The provider's own key, where no pattern would find it ──────────────────
//
// Every key above carries a prefix or a length the generic rules recognise, so none of
// them proves that the provider's credentials reach the redactor. These do: a key
// shorter than the long-token rule, with no vendor prefix, generated at run time so
// that no key-shaped literal sits in the repository.

function patternFreeKey(string $seed): string
{
    return 'vault-'.substr(hash('sha256', $seed), 0, 18);
}

test('the provider’s own key is redacted where no generic pattern would find it', function (string $driver, string $url, string $codeField, string $code, int $status): void {
    $key = patternFreeKey($driver);
    $masked = substr($key, 0, 8).'...'.substr($key, -6);
    $quote = "Key {$key} was rejected; it was also seen as {$masked}.";

    // The control: without the provider's credentials the generic rules leave this key
    // alone, so only the credential-aware path can remove it.
    expect(strlen($key))->toBeLessThan(32)
        ->and(ErrorRedactor::message($quote))->toContain($key)->toContain($masked);

    IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->update(['is_default' => false]);
    $row = aiRow($driver);
    $row->setCredentials(['api_key' => $key]);
    $row->forceFill(['is_active' => true, 'is_default' => true])->save();

    Http::fake([$url => Http::response(['error' => [$codeField => $code, 'message' => $quote]], $status)]);

    $result = app(TextGeneratorContract::class)->generate(new TextGenerationRequest(instruction: 'x', content: 'y'));
    $logged = IntegrationUsageLog::query()->sole();

    $expected = 'Key [redacted] was rejected; it was also seen as [redacted]...[redacted].';

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe($code)
        ->and($result->errorMessage)->toBe($expected)
        ->and($logged->error_message)->toBe($expected);
})->with([
    'OpenAI' => ['openai', 'api.openai.com/*', 'code', 'invalid_api_key', 401],
    'Anthropic' => ['anthropic', 'api.anthropic.com/*', 'type', 'authentication_error', 401],
    'Google Gemini' => ['gemini', 'generativelanguage.googleapis.com/*', 'status', 'INVALID_ARGUMENT', 400],
]);

test('an Admin test shows the diagnosis without the key the vendor quoted, saved or not', function (): void {
    $saved = patternFreeKey('saved');
    $typed = patternFreeKey('typed');

    // The vendor quotes back whatever key it received — in full, and as its first and
    // last characters.
    Http::fake(['api.openai.com/*' => function ($request) {
        $sent = substr((string) $request->header('Authorization')[0], strlen('Bearer '));

        return Http::response(['error' => [
            'code' => 'invalid_api_key',
            'message' => "Key {$sent} was rejected; also seen as ".substr($sent, 0, 8).'...'.substr($sent, -6).'.',
        ]], 401);
    }]);

    $token = tokenWithPermissions(CONFIGURE);
    setUpProvider($this, $token, 'openai', ['api_key' => $saved, 'model' => 'gpt-5.6-luna'])->assertOk();

    $expected = 'Key [redacted] was rejected; also seen as [redacted]...[redacted].';

    // The saved key, tested by name…
    $this->withToken($token)->postJson('/api/v1/admin/ai/check', ['provider' => 'openai'])
        ->assertOk()
        ->assertJsonPath('data.error_message', $expected);

    // …and a key typed into the form and never saved.
    $this->withToken($token)->postJson('/api/v1/admin/ai/check', [
        'provider' => 'openai',
        'api_key' => $typed,
        'model' => 'gpt-5.6-luna',
    ])
        ->assertOk()
        ->assertJsonPath('data.error_message', $expected);

    $shown = [
        $this->withToken($token)->getJson('/api/v1/admin/ai')->assertOk()->content(),
        $this->withToken($token)->getJson('/api/v1/admin/integrations/usage')->assertOk()->content(),
        (string) json_encode(IntegrationUsageLog::query()->pluck('error_message')->all()),
    ];

    foreach ($shown as $text) {
        foreach ([$saved, $typed] as $key) {
            expect($text)->not->toContain($key)
                ->not->toContain(substr($key, 0, 8))
                ->not->toContain(substr($key, -6));
        }
    }

    // The typed key was used for one call and not kept.
    expect(aiRow('openai')->getCredentials())->toBe(['api_key' => $saved]);
});

// ── A credential is not a model ─────────────────────────────────────────────

test('a model ID shaped like a credential is refused, and nothing is stored or sent', function (string $pasted): void {
    Http::fake();
    $token = tokenWithPermissions(CONFIGURE);

    $saved = setUpProvider($this, $token, 'openai', ['api_key' => 'sk-1', 'model' => $pasted])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $tested = $this->withToken($token)->postJson('/api/v1/admin/ai/check', ['provider' => 'openai', 'model' => $pasted])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    foreach ([$saved, $tested] as $response) {
        // Says what went wrong without repeating what was typed.
        expect($response->content())->toContain('looks like an API key')
            ->not->toContain($pasted);
    }

    expect(aiRow('openai')->settings)->toBeNull()
        ->and(aiRow('openai')->hasCredentials())->toBeFalse();

    Http::assertNothingSent();
})->with([
    'an OpenAI key' => [implode('-', ['sk', 'proj', str_repeat('Mq4', 10)])],
    'an Anthropic key' => [implode('-', ['sk', 'ant', 'api03', str_repeat('Lw8', 10)])],
    'a Google key' => ['AIza'.str_repeat('Rt7', 12)],
    'a named credential' => [implode(':', ['api_key', str_repeat('z9', 6)])],
]);

test('ordinary model IDs, including long and qualified ones, are still accepted', function (string $model): void {
    setUpProvider($this, tokenWithPermissions(CONFIGURE), 'openai', ['api_key' => 'sk-1', 'model' => $model])->assertOk();

    expect(aiRow('openai')->settings)->toBe(['model' => $model]);
})->with([
    'gpt-5.6-terra',
    'claude-haiku-4-5',
    'gemini-3.5-flash-lite',
    'models/gemini-2.5-pro',
    'gpt-4o-mini-search-preview-2025-03-11',
    'ft:gpt-4o-mini-2024-07-18:acme:support:9aBcD1eF',
    'claude-3-5-sonnet@20241022',
    'anthropic.claude-3-5-sonnet-20241022-v2:0',
]);

// ── Authorization values, whatever the scheme ───────────────────────────────

test('an Authorization value is removed whatever its scheme', function (string $message, string $clean): void {
    expect(ErrorRedactor::message($message))->toBe($clean);
})->with([
    'Bearer' => ['Rejected Authorization: Bearer abc.def.ghi for this project.', 'Rejected Authorization: Bearer [redacted] for this project.'],
    'Basic' => ['Rejected Authorization: Basic dXNlcjpwYXNz for this project.', 'Rejected Authorization: Basic [redacted] for this project.'],
    'Token' => ['Rejected Authorization: Token 9f86d081884c7d65 for this project.', 'Rejected Authorization: Token [redacted] for this project.'],
    'Digest' => [
        'Rejected Authorization: Digest username="svc", realm="api", nonce="dcd98b71", response="6629fae4" for this project.',
        'Rejected Authorization: Digest [redacted] for this project.',
    ],
    'Negotiate, as a proxy header' => ['Rejected Proxy-Authorization: Negotiate YIIHwgYGKwYB for this project.', 'Rejected Proxy-Authorization: Negotiate [redacted] for this project.'],
    'a quoted header' => ['Sent "authorization": "Basic dXNlcjpwYXNz", then refused.', 'Sent "authorization": "Basic [redacted]", then refused.'],
    'an unregistered scheme' => ['Rejected Authorization: Custom abc123def456 for this project.', 'Rejected Authorization: [redacted] [redacted] for this project.'],
    'no scheme at all' => ['Rejected Authorization: 9f86d081884c7d65 for this project.', 'Rejected Authorization: [redacted] for this project.'],
]);

test('text that only mentions authorization is left alone', function (string $message): void {
    expect(ErrorRedactor::message($message))->toBe($message);
})->with([
    'Authorization header is missing.',
    'The authorization failed for this project.',
    'Check your authorization settings: they are incomplete.',
]);

// ── Gemini ───────────────────────────────────────────────────────────────────

test('Gemini is asked in its own shape, and its answer is read across parts', function (): void {
    IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->update(['is_default' => false]);
    $gemini = aiRow('gemini');
    $gemini->setCredentials(['api_key' => 'AIza-test']);
    $gemini->forceFill(['is_active' => true, 'is_default' => true])->save();

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [
            ['text' => 'weighing the options', 'thought' => true],
            ['text' => 'حف'],
            ['text' => 'ظ'],
        ]]]],
        'usageMetadata' => ['totalTokenCount' => 21],
    ])]);

    $result = app(TextGeneratorContract::class)->generate(new TextGenerationRequest(
        instruction: 'Translate into Arabic.',
        content: 'Save',
        maxOutputTokens: 64,
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->text)->toBe('حفظ')
        ->and($result->units)->toBe(21);

    Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'AIza-test')
        && $request->data()['systemInstruction']['parts'][0]['text'] === 'Translate into Arabic.'
        && $request->data()['contents'][0]['parts'][0]['text'] === 'Save'
        && $request->data()['generationConfig']['maxOutputTokens'] === 64
        && ! str_contains($request->url(), 'AIza-test'));
});

test('a Gemini refusal is reported in its own words', function (): void {
    IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->update(['is_default' => false]);
    $gemini = aiRow('gemini');
    $gemini->setCredentials(['api_key' => 'AIza-bad']);
    $gemini->forceFill(['is_active' => true, 'is_default' => true])->save();

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid.'],
    ], 400)]);

    $result = app(TextGeneratorContract::class)->generate(new TextGenerationRequest(instruction: 'x', content: 'y'));

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('INVALID_ARGUMENT')
        ->and($result->errorMessage)->toBe('API key not valid.');
});

// ── Test before save ─────────────────────────────────────────────────────────

test('a setup can be tested before it is saved, and the key is not kept', function (): void {
    fakeVendors();

    $this->withToken(tokenWithPermissions(CONFIGURE))->postJson('/api/v1/admin/ai/check', [
        'provider' => 'openai',
        'api_key' => 'sk-typed-only',
        'model' => 'gpt-5.6-terra',
    ])
        ->assertOk()
        ->assertJsonPath('data.answered', true)
        ->assertJsonPath('data.model', 'gpt-5.6-terra');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sk-typed-only')
        && $request->data()['model'] === 'gpt-5.6-terra');

    expect(aiRow('openai')->hasCredentials())->toBeFalse()
        ->and(AuditRecord::query()->where('action', AuditAction::AI_PROVIDER_SAVED)->count())->toBe(0);
});

test('testing an unsaved key or model needs permission to configure', function (): void {
    Http::fake();

    $this->withToken(tokenWithPermissions(['integrations.view', 'ai.use']))->postJson('/api/v1/admin/ai/check', [
        'provider' => 'openai',
        'api_key' => 'sk-typed-only',
        'model' => 'gpt-5.6-luna',
    ])->assertForbidden();

    Http::assertNothingSent();
});

test('a saved provider can be tested by name, whether or not it is the default', function (): void {
    fakeVendors();
    $configure = tokenWithPermissions(CONFIGURE);

    setUpProvider($this, $configure, 'anthropic', ['api_key' => 'sk-ant-1', 'model' => 'claude-sonnet-5'])->assertOk();
    setUpProvider($this, $configure, 'openai', ['api_key' => 'sk-stored', 'model' => 'gpt-5.6-luna'])->assertOk();

    resetClient($this);

    $this->withToken(tokenWithPermissions(['integrations.view', 'ai.use']))
        ->postJson('/api/v1/admin/ai/check', ['provider' => 'openai'])
        ->assertOk()
        ->assertJsonPath('data.answered', true)
        ->assertJsonPath('data.driver', 'openai');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sk-stored'));
});

// ── The default ──────────────────────────────────────────────────────────────

test('the default is chosen explicitly, and must be able to answer', function (): void {
    $token = tokenWithPermissions(CONFIGURE);

    setUpProvider($this, $token, 'anthropic', ['api_key' => 'sk-ant-1', 'model' => 'claude-sonnet-5'])->assertOk();
    setUpProvider($this, $token, 'openai', ['api_key' => 'sk-1', 'model' => 'gpt-5.6-luna'])->assertOk();

    $this->withToken($token)->postJson('/api/v1/admin/ai/providers/openai/default')
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    expect(aiRow('anthropic')->is_default)->toBeFalse()
        ->and(aiRow('anthropic')->is_active)->toBeTrue()
        ->and(AuditRecord::query()->where('action', AuditAction::AI_DEFAULT_CHANGED)->orderBy('id')->pluck('context')->all())
        ->toBe([
            // Anthropic, saved first, took the default from the keyless seeded row —
            // implicitly, and still recorded as the change it is…
            ['previous_default' => 'openai', 'default' => 'anthropic'],
            // …and OpenAI took it explicitly.
            ['previous_default' => 'anthropic', 'default' => 'openai'],
        ]);

    $this->withToken($token)->postJson('/api/v1/admin/ai/providers/gemini/default')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'AI_PROVIDER_NOT_READY');
});

test('changing the default leaves every other provider configured', function (): void {
    $token = tokenWithPermissions(CONFIGURE);

    setUpProvider($this, $token, 'openai', ['api_key' => 'sk-1', 'model' => 'gpt-5.6-terra'])->assertOk();
    setUpProvider($this, $token, 'anthropic', ['api_key' => 'sk-ant-1', 'model' => 'claude-haiku-4-5'])->assertOk();
    setUpProvider($this, $token, 'gemini', ['api_key' => 'AIza-1', 'model' => 'gemini-2.5-pro'])->assertOk();

    $this->withToken($token)->postJson('/api/v1/admin/ai/providers/gemini/default')->assertOk();
    $this->withToken($token)->postJson('/api/v1/admin/ai/providers/anthropic/default')->assertOk();

    expect(aiRow('anthropic')->is_default)->toBeTrue();

    foreach (['openai' => 'gpt-5.6-terra', 'anthropic' => 'claude-haiku-4-5', 'gemini' => 'gemini-2.5-pro'] as $driver => $model) {
        $row = aiRow($driver);

        expect($row->hasCredentials())->toBeTrue()
            ->and($row->is_active)->toBeTrue()
            ->and($row->settings)->toBe(['model' => $model]);
    }
});

test('removing a key takes only that provider out, and is recorded', function (): void {
    $token = tokenWithPermissions(CONFIGURE);

    setUpProvider($this, $token, 'openai', ['api_key' => 'sk-1', 'model' => 'gpt-5.6-luna'])->assertOk();
    setUpProvider($this, $token, 'anthropic', ['api_key' => 'sk-ant-1', 'model' => 'claude-sonnet-5'])->assertOk();

    $this->withToken($token)->deleteJson('/api/v1/admin/ai/providers/openai/key')
        ->assertOk()
        ->assertJsonPath('data.has_key', false);

    expect(aiRow('openai')->is_active)->toBeFalse()
        ->and(aiRow('anthropic')->hasCredentials())->toBeTrue()
        ->and(aiRow('anthropic')->is_active)->toBeTrue()
        ->and(AuditRecord::query()->where('action', AuditAction::AI_PROVIDER_KEY_REMOVED)->sole()->context)
        ->toBe(['provider' => 'openai', 'was_default' => true]);

    // The default had its key removed, so nothing answers until somebody chooses —
    // Anthropic is not promoted behind the operator's back (ADR 0044 §3).
    $this->withToken($token)->getJson('/api/v1/admin/ai')
        ->assertOk()
        ->assertJsonPath('data.configured', false);

    $this->withToken($token)->postJson('/api/v1/admin/ai/providers/anthropic/default')
        ->assertOk()
        ->assertJsonPath('data.is_default', true);
});

// ── The trail ────────────────────────────────────────────────────────────────

test('every save is recorded with what changed, and the key never is', function (): void {
    $token = tokenWithPermissions(CONFIGURE);

    setUpProvider($this, $token, 'openai', ['api_key' => 'sk-first-secret', 'model' => 'gpt-5.6-luna'])->assertOk();
    setUpProvider($this, $token, 'openai', ['api_key' => 'sk-second-secret', 'model' => 'gpt-5.6-luna'])->assertOk();
    setUpProvider($this, $token, 'openai', ['model' => 'gpt-5.6-terra'])->assertOk();

    $records = AuditRecord::query()->where('action', AuditAction::AI_PROVIDER_SAVED)->orderBy('id')->get();

    expect($records)->toHaveCount(3)
        ->and($records[0]->context)->toBe([
            'provider' => 'openai',
            'previous_model' => null,
            'model' => 'gpt-5.6-luna',
            'key' => 'set',
            'is_default' => true,
        ])
        ->and($records[1]->context['key'])->toBe('replaced')
        // A change of model reads as one: what it was, and what it became.
        ->and($records[2]->context['key'])->toBe('unchanged')
        ->and($records[2]->context['previous_model'])->toBe('gpt-5.6-luna')
        ->and($records[2]->context['model'])->toBe('gpt-5.6-terra');

    $trail = (string) json_encode(AuditRecord::query()->get()->toArray());

    expect($trail)->not->toContain('sk-first-secret')->not->toContain('sk-second-secret');
});

test('the generic integrations editor refuses AI providers', function (): void {
    $token = tokenWithPermissions(CONFIGURE);
    $openai = aiRow('openai');

    $this->withToken($token)->putJson("/api/v1/admin/integrations/providers/{$openai->id}", ['priority' => 5])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'AI_CONFIGURED_IN_CONTROL_CENTRE');

    $this->withToken($token)->postJson("/api/v1/admin/integrations/providers/{$openai->id}/default")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'AI_CONFIGURED_IN_CONTROL_CENTRE');
});

test('every AI audit action resolves a label in both locales', function (): void {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ([AuditAction::AI_PROVIDER_SAVED, AuditAction::AI_PROVIDER_KEY_REMOVED, AuditAction::AI_DEFAULT_CHANGED] as $action) {
            expect(__('audit.action.'.$action))->not->toBe('audit.action.'.$action);
        }
    }
});

// ── The migration ────────────────────────────────────────────────────────────

test('a model an operator had chosen moves onto the provider that used it', function (): void {
    DB::table('settings')->insert([
        'id' => (string) Str::ulid(),
        'group' => 'ai',
        'key' => 'translation_model',
        'value' => 'gpt-5.6-sol',
        'type' => 'string',
        'is_secret' => false,
        'is_public' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require base_path('app/Modules/Integration/Database/Migrations/2026_09_11_000007_ai_model_belongs_to_the_provider.php');
    $migration->up();

    expect(aiRow('openai')->settings['model'] ?? null)->toBe('gpt-5.6-sol')
        ->and(DB::table('settings')->where('group', 'ai')->where('key', 'translation_model')->exists())->toBeFalse()
        ->and(IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->where('driver', 'gemini')->count())->toBe(1);
});

test('the old default model is not pinned onto a provider', function (): void {
    DB::table('settings')->insert([
        'id' => (string) Str::ulid(),
        'group' => 'ai',
        'key' => 'translation_model',
        'value' => json_encode('gpt-4o-mini'),
        'type' => 'string',
        'is_secret' => false,
        'is_public' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require base_path('app/Modules/Integration/Database/Migrations/2026_09_11_000007_ai_model_belongs_to_the_provider.php');
    $migration->up();

    expect(aiRow('openai')->settings['model'] ?? null)->toBeNull();
});

test('the migration removes the stored address, and a provider is ready exactly when it holds a key', function (): void {
    // As the rows stood before: an empty address on every one, a keyless default
    // switched on, and a keyed provider switched off.
    aiRow('openai')->forceFill(['settings' => ['base_url' => ''], 'is_active' => true])->save();

    $anthropic = aiRow('anthropic');
    $anthropic->setCredentials(['api_key' => 'sk-ant-1']);
    $anthropic->forceFill([
        'settings' => ['base_url' => 'https://gateway.example.test/v1', 'model' => 'claude-haiku-4-5'],
        'is_active' => false,
    ])->save();

    $migration = require base_path('app/Modules/Integration/Database/Migrations/2026_09_11_000007_ai_model_belongs_to_the_provider.php');
    $migration->up();

    expect(aiRow('openai')->settings)->toBeNull()
        ->and(aiRow('openai')->is_active)->toBeFalse()
        ->and(aiRow('anthropic')->settings)->toBe(['model' => 'claude-haiku-4-5'])
        ->and(aiRow('anthropic')->is_active)->toBeTrue()
        ->and(aiRow('anthropic')->getCredentials())->toBe(['api_key' => 'sk-ant-1'])
        ->and(aiRow('gemini')->settings)->toBeNull();
});
