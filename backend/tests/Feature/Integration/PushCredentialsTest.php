<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Integration\Contracts\PushDispatcherContract;
use App\Modules\Integration\Data\ServiceAccount;
use App\Modules\Integration\Database\Seeders\IntegrationProviderSeeder;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Services\Push\FcmAccessToken;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
    $this->seed(IntegrationProviderSeeder::class);
});

// Saving a Firebase service account through the endpoint the Admin actually calls.
//
// The push suite writes its credential straight onto the model, which is how a
// 500-character ceiling that refused every real key went unnoticed: nothing exercised
// the request. These do. The key is generated per run in the shape Google issues —
// PKCS#8, RSA, 2048 bits — so it is realistic in length and structure, and it is used
// nowhere else. There is no private key in this file for a scan to find.

function fcmServiceAccountKey(int $bits = 2048): string
{
    static $pems = [];

    if (! isset($pems[$bits])) {
        $resource = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $exported);
        $pems[$bits] = (string) $exported;
    }

    return $pems[$bits];
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function fcmServiceAccount(array $overrides = []): array
{
    return array_merge([
        'project_id' => 'alphamaster-test',
        'client_email' => 'firebase-adminsdk@alphamaster-test.iam.gserviceaccount.com',
        'private_key' => fcmServiceAccountKey(),
    ], $overrides);
}

function fcmProviderRow(): IntegrationProvider
{
    return IntegrationProvider::query()
        ->forCapability(IntegrationCapability::PUSH)
        ->where('driver', 'fcm')
        ->firstOrFail();
}

/**
 * @param  array<string, mixed>  $payload
 */
function putFcmProvider(mixed $test, array $payload): TestResponse
{
    return $test->withToken(tokenWithPermissions(['integrations.view', 'integrations.update']))
        ->putJson('/api/v1/admin/integrations/providers/'.fcmProviderRow()->id, $payload);
}

// ── The key a real service account carries is accepted ───────────────────────

test('a service-account key of real length is accepted, which the 500-character ceiling refused', function (): void {
    expect(strlen(fcmServiceAccountKey()))->toBeGreaterThan(1500);

    putFcmProvider($this, ['credentials' => fcmServiceAccount()])
        ->assertOk()
        ->assertJsonPath('data.has_credentials', true);

    expect(fcmProviderRow()->getCredentials())->toBe(storedServiceAccount());
});

/**
 * What the platform keeps of a submitted service account.
 *
 * Every request string is trimmed on the way in (TrimStrings), so the key loses the
 * newline after its END line. That is whitespace around a PEM block, which OpenSSL reads
 * either way — the end-to-end suite signs with a key saved through this endpoint.
 *
 * @return array<string, string>
 */
function storedServiceAccount(): array
{
    return array_map('trim', fcmServiceAccount());
}

test('newlines escaped the way a pasted JSON file carries them are accepted', function (): void {
    $escaped = str_replace("\n", '\\n', fcmServiceAccountKey());

    putFcmProvider($this, ['credentials' => fcmServiceAccount(['private_key' => $escaped])])->assertOk();

    // Stored as given. The driver restores the newlines when it signs, so what the
    // operator supplied is what is kept.
    expect(fcmProviderRow()->getCredentials()['private_key'])->toBe($escaped);
});

// ── What was saved is what Google can authenticate ───────────────────────────

function fcmBase64UrlDecode(string $value): string
{
    return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
}

dataset('saved key forms', [
    'as the file carries it' => [fn (): string => fcmServiceAccountKey()],
    'with escaped newlines' => [fn (): string => str_replace("\n", '\\n', fcmServiceAccountKey())],
]);

test('a key saved through the endpoint signs the assertion Google verifies', function (Closure $form): void {
    putFcmProvider($this, ['credentials' => fcmServiceAccount(['private_key' => $form()])])->assertOk();

    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.credentials', 'expires_in' => 3600], 200)]);

    $provider = fcmProviderRow();

    // The driver's own exchange, reading the credential back out of encrypted storage
    // exactly as a push would.
    $token = app(FcmAccessToken::class)->for(
        $provider,
        $provider->getCredentials(),
        'https://www.googleapis.com/auth/firebase.messaging',
        'https://oauth2.googleapis.com/token',
        60,
    );

    expect($token)->toBe('ya29.credentials');

    $exchange = Http::recorded()->first()[0];
    [$header, $claims, $signature] = explode('.', (string) $exchange['assertion']);

    // Verified against the public half of the key, which is what Google holds: an
    // assertion that verifies here is one Google's token endpoint can verify.
    $public = openssl_pkey_get_details(openssl_pkey_get_private(fcmServiceAccountKey()))['key'];

    expect($exchange['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer')
        ->and(openssl_verify($header.'.'.$claims, fcmBase64UrlDecode($signature), $public, OPENSSL_ALGO_SHA256))->toBe(1)
        ->and(json_decode(fcmBase64UrlDecode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT']);

    $decoded = json_decode(fcmBase64UrlDecode($claims), true);

    expect($decoded['iss'])->toBe('firebase-adminsdk@alphamaster-test.iam.gserviceaccount.com')
        ->and($decoded['aud'])->toBe('https://oauth2.googleapis.com/token')
        ->and($decoded['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging')
        ->and($decoded['exp'] - $decoded['iat'])->toBe(3600);
})->with('saved key forms');

test('a 4096-bit key is accepted too', function (): void {
    putFcmProvider($this, ['credentials' => fcmServiceAccount(['private_key' => fcmServiceAccountKey(4096)])])
        ->assertOk();
});

// ── Anything that is not a service account is refused, field by field ────────

dataset('malformed service accounts', [
    'a private key that is not a key' => [['private_key' => 'not-a-key'], 'credentials.private_key'],
    'a truncated key' => [
        fn (): array => ['private_key' => substr(fcmServiceAccountKey(), 0, 900)."\n-----END PRIVATE KEY-----\n"],
        'credentials.private_key',
    ],
    'a key too weak to sign with' => [fn (): array => ['private_key' => fcmServiceAccountKey(1024)], 'credentials.private_key'],
    'a key past the ceiling' => [['private_key' => str_repeat('A', 9000)], 'credentials.private_key'],
    'a project id Google would not issue' => [['project_id' => 'Alpha_Master'], 'credentials.project_id'],
    'an address that is not a service account' => [['client_email' => 'someone@gmail.com'], 'credentials.client_email'],
]);

test('a malformed service account is refused on the field that is wrong', function (array|Closure $override, string $field): void {
    $override = $override instanceof Closure ? $override() : $override;

    putFcmProvider($this, ['credentials' => fcmServiceAccount($override)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(responseKey: 'error.details', errors: [$field]);

    expect(fcmProviderRow()->hasCredentials())->toBeFalse();
})->with('malformed service accounts');

test('a service account missing one of its three fields is refused', function (): void {
    $credentials = fcmServiceAccount();
    unset($credentials['private_key']);

    putFcmProvider($this, ['credentials' => $credentials])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(responseKey: 'error.details', errors: ['credentials.private_key']);
});

test('fields the driver does not read are refused rather than stored', function (): void {
    // A service-account file carries a dozen fields, one of them the key's own id.
    // Storing what nothing uses is keeping secret material for no reason.
    putFcmProvider($this, ['credentials' => fcmServiceAccount(['private_key_id' => 'abc123'])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(responseKey: 'error.details', errors: ['credentials']);

    expect(fcmProviderRow()->hasCredentials())->toBeFalse();
});

test('other drivers keep the ordinary ceiling', function (): void {
    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();

    $this->withToken(tokenWithPermissions(['integrations.view', 'integrations.update']))
        ->putJson('/api/v1/admin/integrations/providers/'.$twilio->id, [
            'credentials' => ['account_sid' => 'AC-test', 'auth_token' => str_repeat('a', 501)],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(responseKey: 'error.details', errors: ['credentials.auth_token']);
});

// ── The key stays protected ──────────────────────────────────────────────────

test('the key is ciphertext at rest and nothing the API returns carries it', function (): void {
    $key = fcmServiceAccountKey();
    $fragment = substr($key, 200, 48);

    $saved = putFcmProvider($this, ['credentials' => fcmServiceAccount()])->assertOk();

    $column = (string) DB::table('integration_providers')->where('id', fcmProviderRow()->id)->value('credentials');

    expect($column)->not->toContain('PRIVATE KEY')
        ->and($column)->not->toContain($fragment)
        ->and($column)->not->toContain('gserviceaccount');

    resetClient($this);

    $listed = $this->withToken(tokenWithPermissions(['integrations.view']))
        ->getJson('/api/v1/admin/integrations/providers')
        ->assertOk();

    foreach ([$saved, $listed] as $response) {
        expect($response->content())->not->toContain('PRIVATE KEY')
            ->and($response->content())->not->toContain($fragment);
    }
});

test('a refusal never echoes the value it refused', function (): void {
    $truncated = substr(fcmServiceAccountKey(), 0, 900);

    $response = putFcmProvider($this, ['credentials' => fcmServiceAccount(['private_key' => $truncated])])
        ->assertUnprocessable();

    expect($response->content())->not->toContain(substr($truncated, 100, 48))
        ->and($response->content())->not->toContain('PRIVATE KEY');
});

// ── A rotation leaves a trace, and the trace holds no secret ─────────────────

test('a rotation is audited as set then replaced, by name and nothing else', function (): void {
    $key = fcmServiceAccountKey();

    putFcmProvider($this, ['credentials' => fcmServiceAccount(), 'is_active' => true])->assertOk();
    resetClient($this);
    // The rotation an operator performs after a key is exposed: the same shape again.
    putFcmProvider($this, ['credentials' => fcmServiceAccount()])->assertOk();

    $records = AuditRecord::query()
        ->where('action', AuditAction::INTEGRATION_PROVIDER_UPDATED)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    expect($records->pluck('subject')->unique()->all())->toBe([fcmProviderRow()->id])
        ->and($records->pluck('context')->all())->toBe([
            ['capability' => 'push', 'driver' => 'fcm', 'fields' => ['is_active'], 'credentials' => 'set'],
            ['capability' => 'push', 'driver' => 'fcm', 'fields' => [], 'credentials' => 'replaced'],
        ]);

    $record = $records->last();

    // No plaintext, no ciphertext, no fragment, no length.
    $written = (string) json_encode($record->toArray());
    $ciphertext = (string) DB::table('integration_providers')->where('id', fcmProviderRow()->id)->value('credentials');

    expect($written)->not->toContain('PRIVATE KEY')
        ->and($written)->not->toContain(substr($key, 200, 48))
        ->and($written)->not->toContain(substr($ciphertext, 20, 40))
        ->and($written)->not->toContain('gserviceaccount')
        ->and($written)->not->toContain((string) strlen($key));
});

test('clearing the credential is audited as cleared', function (): void {
    putFcmProvider($this, ['credentials' => fcmServiceAccount()])->assertOk();
    resetClient($this);

    putFcmProvider($this, ['credentials' => null])
        ->assertOk()
        ->assertJsonPath('data.has_credentials', false);

    expect(AuditRecord::query()->where('action', AuditAction::INTEGRATION_PROVIDER_UPDATED)->latest('created_at')->latest('id')->first()?->context['credentials'])
        ->toBe('cleared');
});

test('renaming a provider records the field and leaves its credential alone', function (): void {
    putFcmProvider($this, ['credentials' => fcmServiceAccount()])->assertOk();
    resetClient($this);

    putFcmProvider($this, ['label' => 'Firebase (production)'])->assertOk();

    $records = AuditRecord::query()->where('action', AuditAction::INTEGRATION_PROVIDER_UPDATED)->get();

    expect($records)->toHaveCount(2)
        ->and($records->pluck('context')->all())->toContain([
            'capability' => 'push',
            'driver' => 'fcm',
            'fields' => ['label'],
            'credentials' => 'unchanged',
        ])
        ->and(fcmProviderRow()->getCredentials())->toBe(storedServiceAccount());
});

// ── The service-account file, pasted whole ───────────────────────────────────
//
// What the Admin now sends: the file exactly as Firebase issues it, in one field. The
// platform validates it and keeps only the three values the driver reads.

/**
 * A service-account document in the shape Firebase issues, around a generated key.
 *
 * @param  array<string, mixed>  $overrides
 */
function serviceAccountDocument(array $overrides = []): string
{
    return (string) json_encode(array_merge([
        'type' => 'service_account',
        'project_id' => 'alphamaster-test',
        'private_key_id' => str_repeat('f', 40),
        'private_key' => fcmServiceAccountKey(),
        'client_email' => 'firebase-adminsdk@alphamaster-test.iam.gserviceaccount.com',
        'client_id' => '100000000000000000001',
        'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk%40alphamaster-test.iam.gserviceaccount.com',
        'universe_domain' => 'googleapis.com',
    ], $overrides), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

test('the whole service-account file is accepted and becomes the three values the driver reads', function (): void {
    $response = putFcmProvider($this, ['service_account_json' => serviceAccountDocument(), 'is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.has_credentials', true)
        ->assertJsonPath('data.credential_summary.project_id', 'alphamaster-test');

    // The file's other fields — the key's id, the client id — are not kept.
    expect(fcmProviderRow()->getCredentials())->toBe(fcmServiceAccount())
        ->and(app(PushDispatcherContract::class)->isConfigured())->toBeTrue();

    // Nothing from the file comes back but the project.
    expect($response->content())->not->toContain('PRIVATE KEY')
        ->and($response->content())->not->toContain(str_repeat('f', 40))
        ->and($response->content())->not->toContain('100000000000000000001')
        ->and($response->content())->not->toContain('firebase-adminsdk@');
});

test('a file saved through the endpoint signs the assertion Google verifies', function (): void {
    putFcmProvider($this, ['service_account_json' => serviceAccountDocument()])->assertOk();

    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.document', 'expires_in' => 3600], 200)]);

    $provider = fcmProviderRow();

    expect(app(FcmAccessToken::class)->for(
        $provider,
        $provider->getCredentials(),
        'https://www.googleapis.com/auth/firebase.messaging',
        ServiceAccount::TOKEN_URI,
        60,
    ))->toBe('ya29.document');

    [$header, $claims, $signature] = explode('.', (string) Http::recorded()->first()[0]['assertion']);
    $public = openssl_pkey_get_details(openssl_pkey_get_private(fcmServiceAccountKey()))['key'];

    expect(openssl_verify($header.'.'.$claims, fcmBase64UrlDecode($signature), $public, OPENSSL_ALGO_SHA256))->toBe(1);
});

dataset('unusable service-account files', [
    'text that is not JSON' => [fn (): string => 'not json at all', 'This is not a service-account file'],
    'a JSON list' => [fn (): string => '["service_account"]', 'This is not a service-account file'],
    'another kind of credential' => [fn (): string => serviceAccountDocument(['type' => 'authorized_user']), 'its type must be service_account'],
    'a project id Google would not issue' => [fn (): string => serviceAccountDocument(['project_id' => 'Alpha_Master']), 'project_id'],
    'an address that is not a service account' => [fn (): string => serviceAccountDocument(['client_email' => 'someone@gmail.com']), 'client_email'],
    'no private key' => [fn (): string => serviceAccountDocument(['private_key' => null]), 'private_key'],
    'a key too weak to sign with' => [fn (): string => serviceAccountDocument(['private_key' => fcmServiceAccountKey(1024)]), 'private_key'],
    'a token endpoint that is not Google\'s' => [fn (): string => serviceAccountDocument(['token_uri' => 'https://example.test/token']), 'token_uri'],
]);

test('an unusable file is refused, says why, and stores nothing', function (Closure $document, string $reason): void {
    $response = putFcmProvider($this, ['service_account_json' => $document()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(responseKey: 'error.details', errors: ['service_account_json']);

    expect($response->json('error.details.service_account_json.0'))->toContain($reason)
        // The refusal names the part of the file, never its contents.
        ->and($response->content())->not->toContain('PRIVATE KEY')
        ->and($response->content())->not->toContain(substr(fcmServiceAccountKey(), 200, 48))
        ->and(fcmProviderRow()->hasCredentials())->toBeFalse();
})->with('unusable service-account files');

test('a service-account file is refused for any other provider', function (): void {
    $twilio = IntegrationProvider::query()->where('driver', 'twilio')->firstOrFail();

    $this->withToken(tokenWithPermissions(['integrations.view', 'integrations.update']))
        ->putJson('/api/v1/admin/integrations/providers/'.$twilio->id, ['service_account_json' => serviceAccountDocument()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(responseKey: 'error.details', errors: ['service_account_json']);

    expect($twilio->refresh()->hasCredentials())->toBeFalse();
});

test('a file and a credential map in one request are refused rather than guessed between', function (): void {
    putFcmProvider($this, [
        'service_account_json' => serviceAccountDocument(),
        'credentials' => fcmServiceAccount(),
    ])->assertUnprocessable();

    expect(fcmProviderRow()->hasCredentials())->toBeFalse();
});

test('replacing and removing are separate acts, and saving anything else removes nothing', function (): void {
    putFcmProvider($this, ['service_account_json' => serviceAccountDocument()])->assertOk();
    resetClient($this);

    // An ordinary save sends no credential, and keeps the one stored.
    putFcmProvider($this, ['label' => 'Firebase (production)'])
        ->assertOk()
        ->assertJsonPath('data.has_credentials', true)
        ->assertJsonPath('data.credential_summary.project_id', 'alphamaster-test');
    resetClient($this);

    // A replacement is a whole new file.
    putFcmProvider($this, ['service_account_json' => serviceAccountDocument(['project_id' => 'alphamaster-next'])])
        ->assertOk()
        ->assertJsonPath('data.credential_summary.project_id', 'alphamaster-next');
    resetClient($this);

    // Removal is only ever explicit.
    putFcmProvider($this, ['credentials' => null])
        ->assertOk()
        ->assertJsonPath('data.has_credentials', false)
        ->assertJsonPath('data.credential_summary', null);

    $trail = AuditRecord::query()
        ->where('action', AuditAction::INTEGRATION_PROVIDER_UPDATED)
        ->orderBy('created_at')
        ->orderBy('id')
        ->pluck('context')
        ->map(fn (array $context): string => $context['credentials'])
        ->all();

    expect($trail)->toBe(['set', 'unchanged', 'replaced', 'cleared']);
});

test('an edit that changes nothing leaves no record', function (): void {
    putFcmProvider($this, ['label' => fcmProviderRow()->label])->assertOk();

    expect(AuditRecord::query()->where('action', AuditAction::INTEGRATION_PROVIDER_UPDATED)->count())->toBe(0);
});
