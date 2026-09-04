<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
});

/**
 * Every kind of response the platform emits, including those that never reached
 * a route.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function responsePaths(): array
{
    return [
        'success' => ['GET', '/api/v1/health'],
        'unauthenticated' => ['GET', '/api/v1/admin/languages'],
        'unmatched route' => ['GET', '/api/v1/no-such-route'],
        'wrong method' => ['DELETE', '/api/v1/health'],
        'validation' => ['POST', '/api/v1/auth/login'],
    ];
}

// ── The locale is negotiated on every path ───────────────────────────────────

test('every response declares the negotiated language', function (): void {
    // The defect this closes: as API-group middleware, locale negotiation never
    // ran for a request that matched no route, and Laravel's middleware priority
    // hoisted Authenticate ahead of the group. A 404, 405 or 401 was answered in
    // the configuration default and carried no Content-Language at all.
    foreach (responsePaths() as $name => [$method, $uri]) {
        $response = $this->withHeaders(['X-Locale' => 'ar'])->json($method, $uri);

        expect($response->headers->get('Content-Language'))
            ->toBe('ar', $name.' did not declare the negotiated language')
            ->and($response->headers->get('X-Direction'))
            ->toBe('rtl', $name.' did not declare the direction');
    }
});

test('every response declares the default language when none is asked for', function (): void {
    foreach (responsePaths() as $name => [$method, $uri]) {
        $response = $this->json($method, $uri);

        expect($response->headers->get('Content-Language'))->toBe('en', $name)
            ->and($response->headers->get('X-Direction'))->toBe('ltr', $name);
    }
});

test('locale negotiation runs before authentication', function (): void {
    // Authenticate is in Laravel's middleware priority list and the API group is
    // not, so as group middleware SetLocale was sorted behind it and a rejected
    // request was answered before any locale existed.
    $response = $this->withHeaders(['X-Locale' => 'ar'])->getJson('/api/v1/admin/languages');

    expect($response->status())->toBe(401)
        ->and($response->headers->get('Content-Language'))->toBe('ar')
        ->and($response->json('error.message'))->toContain('يلزم تسجيل الدخول');
});

test('locale negotiation runs for a request that matches no route', function (): void {
    $response = $this->withHeaders(['X-Locale' => 'ar'])->getJson('/api/v1/no-such-route');

    expect($response->status())->toBe(404)
        ->and($response->headers->get('Content-Language'))->toBe('ar')
        ->and($response->json('error.message'))->toContain('غير موجود');
});

// ── The handler messages ─────────────────────────────────────────────────────

test('each exception handler answers in the requested language', function (): void {
    $expected = [
        ['GET', '/api/v1/no-such-route', 404, 'المسار أو المورد المطلوب غير موجود.'],
        ['DELETE', '/api/v1/health', 405, 'طريقة الطلب غير مسموح بها لهذا المسار.'],
        ['GET', '/api/v1/admin/languages', 401, 'يلزم تسجيل الدخول للوصول إلى هذا المورد.'],
        ['POST', '/api/v1/auth/login', 422, 'البيانات المُرسلة غير صالحة.'],
    ];

    foreach ($expected as [$method, $uri, $status, $message]) {
        $response = $this->withHeaders(['X-Locale' => 'ar'])->json($method, $uri);

        expect($response->status())->toBe($status)
            ->and($response->json('error.message'))->toBe($message);
    }
});

test('the English wording is exactly what it was before localization', function (): void {
    // Localizing must not reword the default-locale contract. These four strings
    // were captured from the running application before the change.
    $expected = [
        ['GET', '/api/v1/no-such-route', 'The requested route or resource could not be found.'],
        ['DELETE', '/api/v1/health', 'The HTTP method is not allowed for this route.'],
        ['GET', '/api/v1/admin/languages', 'Authentication is required to access this resource.'],
        ['POST', '/api/v1/auth/login', 'The given data was invalid.'],
    ];

    foreach ($expected as [$method, $uri, $message]) {
        expect($this->json($method, $uri)->json('error.message'))->toBe($message);
    }
});

// ── error.code is contract ───────────────────────────────────────────────────

test('error code is identical in every locale', function (): void {
    // ADR 0031: `code` is a technical identifier a client matches on, and is
    // never localized. Only the message moves.
    $paths = [
        ['GET', '/api/v1/no-such-route', 'NOT_FOUND'],
        ['DELETE', '/api/v1/health', 'METHOD_NOT_ALLOWED'],
        ['GET', '/api/v1/admin/languages', 'UNAUTHENTICATED'],
        ['POST', '/api/v1/auth/login', 'VALIDATION_ERROR'],
    ];

    foreach ($paths as [$method, $uri, $code]) {
        foreach (['en', 'ar', 'fr'] as $locale) {
            expect($this->withHeaders(['X-Locale' => $locale])->json($method, $uri)->json('error.code'))
                ->toBe($code, $uri.' in '.$locale);
        }
    }
});

test('a message differs between locales while its code does not', function (): void {
    $arabic = $this->withHeaders(['X-Locale' => 'ar'])->getJson('/api/v1/no-such-route');

    // withHeaders persists on the test client, so the second request would
    // otherwise carry X-Locale: ar too and the comparison would pass vacuously.
    resetClient($this);

    $english = $this->getJson('/api/v1/no-such-route');

    expect($arabic->json('error.code'))->toBe($english->json('error.code'))
        ->and($arabic->json('error.message'))->not->toBe($english->json('error.message'));
});

// ── The success envelope ─────────────────────────────────────────────────────

test('a success message is resolved against the request locale', function (): void {
    $response = $this->withHeaders(['Authorization' => 'Bearer '.adminToken(), 'X-Locale' => 'ar'])
        ->postJson('/api/v1/admin/languages', [
            'code' => 'de',
            'name' => 'German',
            'native_name' => 'Deutsch',
            'direction' => 'ltr',
        ]);

    expect($response->status())->toBe(201)
        ->and($response->json('message'))->toBe('تم إنشاء اللغة بنجاح.');
});

test('the same success message reads in English', function (): void {
    $response = $this->withHeaders(['Authorization' => 'Bearer '.adminToken()])
        ->postJson('/api/v1/admin/languages', [
            'code' => 'de',
            'name' => 'German',
            'native_name' => 'Deutsch',
            'direction' => 'ltr',
        ]);

    expect($response->json('message'))->toBe('Language created successfully.');
});

// ── What deliberately did not change ─────────────────────────────────────────

test('a message that is not a key passes through unchanged', function (): void {
    // Domain exceptions still carry sentences rather than keys, and the envelope
    // returns them as they are. This is the documented boundary of this change,
    // asserted so that converting them later is a visible change and not a
    // silent one.
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.test',
        'password' => 'wrong-password',
    ]);

    expect($response->json('error.code'))->toBe('INVALID_CREDENTIALS')
        ->and($response->json('error.message'))->toBe('The provided credentials are incorrect.');
});

test('every key this change introduced exists in both dictionaries', function (): void {
    /** @var array<string, string> $en */
    $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true);
    /** @var array<string, string> $ar */
    $ar = json_decode((string) file_get_contents(base_path('lang/ar.json')), true);

    // The keys this change introduced are the handler and envelope messages:
    // `api.error.<name>`, `api.language.<name>` and `api.settings.<name>`.
    // Messages carried by domain exceptions add a module segment
    // (`api.error.auth.<name>`) and belong to their own test.
    $keys = array_values(array_filter(
        array_keys($en),
        static fn (string $key): bool => (bool) preg_match(
            '/^api\.(error|language|settings)\.[a-z_]+$/',
            $key
        )
    ));

    expect($keys)->toHaveCount(16);

    foreach ($keys as $key) {
        expect($ar)->toHaveKey($key)
            ->and($ar[$key])->not->toBe($en[$key], $key.' is untranslated');
    }
});
