<?php

declare(strict_types=1);

use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Ai\TextGenerationResult;
use App\Modules\Core\Ai\TextGeneratorContract;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Enums\UsageStatus;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Models\IntegrationUsageLog;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);
});

// The AI capability, proven without anybody's API key.
//
// Every driver here is written against Laravel's HTTP client rather than a vendor SDK,
// which is what makes this possible: `Http::fake()` intercepts at the wire and the real
// driver code runs — request shape, error mapping, token accounting and all. A fake
// *driver* would have proven only that a fake driver works.
//
// What is deliberately absent is a log-style provider. A generator that answers without
// asking a vendor produces plausible text with no relationship to the request, which is
// worse than no answer when the answer is a translation somebody may accept.

/** Activate one AI vendor with a credential, as an operator would. */
function activateAi(string $driver = 'openai'): IntegrationProvider
{
    // One default per capability is a partial unique index, so the old one is cleared
    // before the new one is set — the order the admin endpoint uses, and the order a
    // database with that index requires.
    IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->update(['is_default' => false]);

    // Read after the statement that contradicts it. A model loaded first would still
    // hold `is_default = true` from the seeder, setting it true again would be no
    // change, and Eloquent would write nothing over a row the update had just falsified.
    /** @var IntegrationProvider $provider */
    $provider = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->where('driver', $driver)
        ->firstOrFail();

    $provider->setCredentials(['api_key' => 'sk-not-a-real-key']);
    $provider->forceFill(['is_active' => true, 'is_default' => true])->save();

    return $provider->refresh();
}

function askForText(string $content = 'Save'): TextGenerationResult
{
    return app(TextGeneratorContract::class)->generate(new TextGenerationRequest(
        instruction: 'Translate.',
        content: $content,
        model: 'a-model',
        maxOutputTokens: 64,
    ));
}

test('a platform with no AI provider says so rather than failing obscurely', function (): void {
    // Seeded inactive, which is how a fresh installation arrives.
    expect(app(TextGeneratorContract::class)->isConfigured())->toBeFalse();

    $result = askForText();

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('NOT_CONFIGURED');
});

test('an active provider without a credential is not configured', function (): void {
    IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->where('driver', 'openai')
        ->update(['is_active' => true, 'is_default' => true]);

    // Active is not the same as usable. An interface offering a suggestion button here
    // would offer one that always fails.
    expect(app(TextGeneratorContract::class)->isConfigured())->toBeFalse();
});

test('a generation reaches the vendor in its own request shape', function (): void {
    activateAi('openai');
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => 'حفظ']]],
        'usage' => ['total_tokens' => 12],
    ], 200)]);

    $result = askForText('Save');

    expect($result->successful)->toBeTrue()
        ->and($result->text)->toBe('حفظ')
        ->and($result->units)->toBe(12);

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return str_contains($request->url(), 'chat/completions')
            && $body['model'] === 'a-model'
            && $body['max_tokens'] === 64
            && $body['messages'][0]['role'] === 'system'
            && $body['messages'][1]['content'] === 'Save';
    });
});

test('a second vendor is asked the same question in its own shape', function (): void {
    activateAi('anthropic');
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['text' => 'حفظ']],
        'usage' => ['input_tokens' => 8, 'output_tokens' => 4],
    ], 200)]);

    $result = askForText('Save');

    // Two drivers exist from the start on purpose: one behind a manager is a pattern
    // nobody has tested. The differences are where a single-vendor design would leak —
    // a top-level system field, a mandatory version header, and usage split in two.
    expect($result->successful)->toBeTrue()
        ->and($result->text)->toBe('حفظ')
        ->and($result->units)->toBe(12);

    Http::assertSent(fn ($request): bool => isset($request->data()['system'])
        && $request->hasHeader('anthropic-version'));
});

test('a vendor refusal is an outcome rather than an exception', function (): void {
    activateAi();
    Http::fake(['api.openai.com/*' => Http::response([
        'error' => ['code' => 'model_not_found', 'message' => 'No such model.'],
    ], 404)]);

    $result = askForText();

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('model_not_found')
        ->and($result->errorMessage)->toBe('No such model.');
});

test('a timeout is reported as one rather than escaping', function (): void {
    activateAi();
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $result = askForText();

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('TRANSPORT_ERROR')
        ->and($result->errorMessage)->toContain('timed out');
});

test('a 200 carrying no text is a failure rather than an empty suggestion', function (): void {
    activateAi();
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '   ']]]], 200)]);

    // A blank suggestion offered to a translator looks like the platform's fault.
    expect(askForText()->errorCode)->toBe('EMPTY_RESPONSE');
});

test('an unreadable credential is a failure for this provider, not a crash', function (): void {
    $provider = activateAi();

    // Ciphertext that will not decrypt: what a restored database with a rotated
    // APP_KEY actually looks like.
    $provider->forceFill(['credentials' => 'not-really-encrypted'])->save();

    $result = askForText();

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('CREDENTIALS_UNREADABLE');
});

test('a missing api key is refused before the vendor is called', function (): void {
    IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->where('driver', 'openai')
        ->update(['is_active' => true, 'is_default' => true]);

    Http::fake();

    expect(askForText()->errorCode)->toBe('MISCONFIGURED');

    Http::assertNothingSent();
});

// ── No failover, which is the decision this capability is built around ────────

test('a failing provider is not silently replaced by another', function (): void {
    activateAi('openai');

    // A second vendor, active and credentialled, exactly the arrangement that would
    // trigger failover for SMS.
    /** @var IntegrationProvider $anthropic */
    $anthropic = IntegrationProvider::query()
        ->forCapability(IntegrationCapability::AI)
        ->where('driver', 'anthropic')
        ->firstOrFail();
    $anthropic->setCredentials(['api_key' => 'sk-also-not-real']);
    $anthropic->forceFill(['is_active' => true])->save();

    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['message' => 'Down.']], 503),
        'api.anthropic.com/*' => Http::response(['content' => [['text' => 'should not be reached']]], 200),
    ]);

    $result = askForText();

    // Failover exists because a transport is interchangeable — a recipient cannot tell
    // which carrier delivered a message. A generator is not: falling to a second vendor
    // silently changes what the platform produced (ADR 0044 §3).
    expect($result->successful)->toBeFalse();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'anthropic'));
});

// ── What is recorded, and what is never recorded ──────────────────────────────

test('every attempt is logged with the units the vendor reported', function (): void {
    activateAi();
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => 'حفظ']]],
        'usage' => ['total_tokens' => 31],
    ], 200)]);

    askForText();

    $log = IntegrationUsageLog::query()->where('capability', 'ai')->sole();

    expect($log->status)->toBe(UsageStatus::SUCCESS)
        ->and($log->units)->toBe(31)
        ->and($log->driver)->toBe('openai')
        ->and($log->duration_ms)->not->toBeNull();
});

test('a failed attempt is logged too, so a failing vendor leaves evidence', function (): void {
    activateAi();
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Nope.']], 500)]);

    askForText();

    $log = IntegrationUsageLog::query()->where('capability', 'ai')->sole();

    expect($log->status)->toBe(UsageStatus::FAILURE)
        ->and($log->units)->toBeNull();
});

test('the prompt and the answer never reach the usage log', function (): void {
    activateAi();
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => 'A SECRET ANSWER']]],
        'usage' => ['total_tokens' => 5],
    ], 200)]);

    app(TextGeneratorContract::class)->generate(new TextGenerationRequest(
        instruction: 'A SECRET INSTRUCTION',
        content: 'A SECRET SOURCE STRING',
        model: 'a-model',
    ));

    $row = (string) json_encode(IntegrationUsageLog::query()->where('capability', 'ai')->sole()->toArray());

    // A usage log exists to operate the integration. One carrying prompts would be a
    // copy of the platform's content in a table with different access rules.
    expect($row)->not->toContain('SECRET INSTRUCTION')
        ->not->toContain('SECRET SOURCE STRING')
        ->not->toContain('SECRET ANSWER');
});

test('a credential never appears in any serialisation of a provider', function (): void {
    $provider = activateAi();

    $serialised = (string) json_encode($provider->fresh()->toArray());

    expect($serialised)->not->toContain('sk-not-a-real-key')
        ->and($provider->hasCredentials())->toBeTrue();
});
