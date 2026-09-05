<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
});

/**
 * The exception Laravel's throttle middleware raises, with the headers it
 * actually attaches.
 */
function throttleException(int $retryAfter = 42, int $limit = 60, int $remaining = 0): ThrottleRequestsException
{
    return new ThrottleRequestsException('Too Many Attempts.', null, [
        'Retry-After' => $retryAfter,
        'X-RateLimit-Limit' => $limit,
        'X-RateLimit-Remaining' => $remaining,
    ]);
}

/**
 * Render that exception through the application's own handler.
 */
function renderThrottle(ThrottleRequestsException $e, string $locale = 'en'): Response
{
    $request = Request::create('/api/v1/languages', 'GET');
    $request->headers->set('Accept', 'application/json');

    app()->setLocale($locale);

    return app(ExceptionHandler::class)->render($request, $e);
}

// ── The contract ─────────────────────────────────────────────────────────────

test('a throttled request answers with the platform error code, not HTTP_ERROR', function (): void {
    // The generic HttpException handler maps anything outside 401/403/404/405 to
    // HTTP_ERROR. A 429 is a first-class outcome here and gets the same code the
    // auth throttles already return.
    $response = renderThrottle(throttleException());
    $body = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(429)
        ->and($body['success'])->toBeFalse()
        ->and($body['error']['code'])->toBe('TOO_MANY_ATTEMPTS');
});

test('the message is localized rather than the framework English', function (): void {
    $english = json_decode((string) renderThrottle(throttleException(30), 'en')->getContent(), true);
    $arabic = json_decode((string) renderThrottle(throttleException(30), 'ar')->getContent(), true);

    expect($english['error']['message'])->toBe('Too many requests. Please retry in 30 seconds.')
        ->and($arabic['error']['message'])->toBe('عدد الطلبات كبير. أعد المحاولة بعد 30 ثانية.')
        ->and($english['error']['message'])->not->toBe('Too Many Attempts.')
        ->and($arabic['error']['message'])->not->toBe($english['error']['message']);
});

test('the error code is identical in every locale', function (): void {
    foreach (['en', 'ar', 'fr'] as $locale) {
        $body = json_decode((string) renderThrottle(throttleException(), $locale)->getContent(), true);

        expect($body['error']['code'])->toBe('TOO_MANY_ATTEMPTS', 'code moved in '.$locale);
    }
});

test('retry_after is reported in the details, from the header the limiter set', function (): void {
    $body = json_decode((string) renderThrottle(throttleException(retryAfter: 17))->getContent(), true);

    expect($body['error']['details']['retry_after'])->toBe(17);
});

test('every header the limiter produced survives the renderer', function (): void {
    // The generic handler builds a fresh JsonResponse and drops them. A client
    // that cannot read Retry-After has to guess when to come back.
    $response = renderThrottle(throttleException(retryAfter: 90, limit: 120, remaining: 0));

    expect($response->headers->get('Retry-After'))->toBe('90')
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('120')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0');
});

test('a throttle exception carrying no headers still renders', function (): void {
    // Nothing guarantees the headers are present; a missing Retry-After must not
    // produce a null or a crash.
    $response = renderThrottle(new ThrottleRequestsException('Too Many Attempts.'));
    $body = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(429)
        ->and($body['error']['code'])->toBe('TOO_MANY_ATTEMPTS')
        ->and($body['error']['details']['retry_after'])->toBe(0);
});

// ── What this must not disturb ───────────────────────────────────────────────

test('the shape matches the 429 the auth throttles already return', function (): void {
    // AuthController::throttledResponse returns TOO_MANY_ATTEMPTS with
    // details.retry_after and a Retry-After header, without passing through any
    // renderer. A client must not be able to tell the two apart.
    $body = json_decode((string) renderThrottle(throttleException(60))->getContent(), true);

    expect(array_keys($body))->toBe(['success', 'error'])
        ->and(array_keys($body['error']))->toBe(['code', 'message', 'details'])
        ->and(array_keys($body['error']['details']))->toBe(['retry_after']);
});

test('other HTTP errors still map as they did', function (): void {
    // The new renderer is registered before the generic one; nothing else may
    // start answering as TOO_MANY_ATTEMPTS.
    $request = Request::create('/api/v1/languages', 'GET');
    $request->headers->set('Accept', 'application/json');

    $handler = app(ExceptionHandler::class);

    $teapot = $handler->render($request, new HttpException(418, 'I am a teapot'));
    $notFound = $handler->render($request, new NotFoundHttpException);

    expect(json_decode((string) $teapot->getContent(), true)['error']['code'])->toBe('HTTP_ERROR')
        ->and(json_decode((string) $notFound->getContent(), true)['error']['code'])->toBe('NOT_FOUND');
});
