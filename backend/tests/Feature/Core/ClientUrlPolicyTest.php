<?php

declare(strict_types=1);

use App\Modules\Core\Support\ClientUrlPolicy;

/*
 * Which configured addresses the platform may send a person to (ADR 0050 §11, §12).
 *
 * The addresses here are fixtures under the reserved `.test` domain, not defaults: the
 * platform ships with none.
 */

test('a usable return address passes in every environment', function (string $uri): void {
    expect((new ClientUrlPolicy(production: false))->redirectUriProblem($uri))->toBeNull()
        ->and((new ClientUrlPolicy(production: true))->redirectUriProblem($uri))->toBeNull();
})->with([
    'a web client' => 'https://client.example.test/auth/callback',
    'with a query' => 'https://client.example.test/auth/callback?from=signin',
    'a native app, path form' => 'com.example.app:/oauth2redirect',
    'a native app, authority form' => 'com.example.app://callback',
    'upper case scheme and host' => 'HTTPS://Client.Example.Test/auth/callback',
]);

test('each unsafe address is refused with its own reason', function (mixed $uri, string $problem): void {
    expect((new ClientUrlPolicy(production: false))->redirectUriProblem($uri))->toBe($problem)
        ->and((new ClientUrlPolicy(production: true))->redirectUriProblem($uri))->toBe($problem);
})->with([
    'not text' => [123, ClientUrlPolicy::NOT_TEXT],
    'empty' => ['', ClientUrlPolicy::NOT_ABSOLUTE],
    'relative' => ['/auth/callback', ClientUrlPolicy::NOT_ABSOLUTE],
    'scheme-relative' => ['//client.example.test/auth/callback', ClientUrlPolicy::NOT_ABSOLUTE],
    'no host' => ['https:///auth/callback', ClientUrlPolicy::NOT_ABSOLUTE],
    'a space' => ['https://client.example.test/auth call', ClientUrlPolicy::NOT_ABSOLUTE],
    'surrounding whitespace' => [' https://client.example.test/auth/callback', ClientUrlPolicy::NOT_ABSOLUTE],
    'a fragment' => ['https://client.example.test/auth/callback#done', ClientUrlPolicy::FRAGMENT],
    'a wildcard' => ['https://*.example.test/auth/callback', ClientUrlPolicy::WILDCARD],
    'credentials' => ['https://user:secret@client.example.test/auth/callback', ClientUrlPolicy::USERINFO],
    'javascript' => ['javascript:alert(1)', ClientUrlPolicy::SCHEME],
    'data' => ['data:text/html,hello', ClientUrlPolicy::SCHEME],
    'file' => ['file:///etc/passwd', ClientUrlPolicy::SCHEME],
    'an app scheme not in reverse-domain form' => ['myapp:/callback', ClientUrlPolicy::SCHEME],
    'too long' => ['https://client.example.test/'.str_repeat('a', 2048), ClientUrlPolicy::TOO_LONG],
]);

test('production refuses plain http and anything on this machine, and development does not', function (string $uri, string $problem): void {
    $development = new ClientUrlPolicy(production: false);
    $production = new ClientUrlPolicy(production: true);

    expect($development->redirectUriProblem($uri))->toBeNull()
        ->and($development->pageUrlProblem($uri))->toBeNull()
        ->and($production->redirectUriProblem($uri))->toBe($problem)
        ->and($production->pageUrlProblem($uri))->toBe($problem);
})->with([
    'plain http' => ['http://client.example.test/reset-password', ClientUrlPolicy::INSECURE],
    'localhost over https' => ['https://localhost/reset-password', ClientUrlPolicy::LOOPBACK],
    'localhost with a port' => ['http://localhost:8080/reset-password', ClientUrlPolicy::LOOPBACK],
    'localhost with a trailing dot' => ['https://localhost./reset-password', ClientUrlPolicy::LOOPBACK],
    'a .localhost name' => ['https://app.localhost/reset-password', ClientUrlPolicy::LOOPBACK],
    'IPv4 loopback' => ['https://127.0.0.1/reset-password', ClientUrlPolicy::LOOPBACK],
    'the rest of 127/8' => ['https://127.10.0.1/reset-password', ClientUrlPolicy::LOOPBACK],
    'IPv6 loopback' => ['https://[::1]/reset-password', ClientUrlPolicy::LOOPBACK],
    'the unspecified address' => ['https://0.0.0.0/reset-password', ClientUrlPolicy::LOOPBACK],
]);

test('a reset page is always a web page', function (): void {
    expect((new ClientUrlPolicy(production: false))->pageUrlProblem('com.example.app:/reset'))->toBe(ClientUrlPolicy::SCHEME)
        ->and((new ClientUrlPolicy(production: false))->pageUrlProblem('https://client.example.test/reset-password'))->toBeNull();
});

test('every refusal reads in both languages', function (): void {
    $problems = array_filter(
        (new ReflectionClass(ClientUrlPolicy::class))->getConstants(),
        static fn (mixed $value): bool => is_string($value),
    );
    $problems[] = 'duplicate';

    foreach ($problems as $problem) {
        $key = 'validation.client_url.problem.'.$problem;
        $english = __($key, [], 'en');
        $arabic = __($key, [], 'ar');

        expect($english)->not->toBe($key, $problem.' has no English message')
            ->and($arabic)->not->toBe($key, $problem.' has no Arabic message')
            ->and($arabic)->not->toBe($english, $problem.' is not translated');
    }
});
