<?php

declare(strict_types=1);

use App\Modules\Integration\Exceptions\SocialProviderException;
use App\Modules\Integration\Services\SocialLogin\IdTokenVerifier;
use App\Modules\Integration\Services\SocialLogin\RsaPublicKey;
use Tests\Support\FakeGoogle;

/*
 * The ID token checks, one at a time (ADR 0050 §8).
 *
 * Times in the datasets are a day away rather than a minute: a dataset is built when the
 * file loads, and a slow run can take longer than the leeway before its test executes.
 */

const VERIFIER_NONCE = 'the-nonce-this-sign-in-stored';

/**
 * @param  array<string, mixed>  $claims
 * @param  array<string, mixed>  $header
 * @return array<string, mixed>
 */
function verifyIdToken(array $claims = [], array $header = [], ?OpenSSLAsymmetricKey $key = null, string $audience = FakeGoogle::CLIENT_ID): array
{
    $published = FakeGoogle::publishedKey();
    $pem = RsaPublicKey::pemFromJwk($published['n'], $published['e']);

    return app(IdTokenVerifier::class)->verify(
        FakeGoogle::idToken(FakeGoogle::claims(VERIFIER_NONCE, $claims), $header, $key),
        static fn (string $keyId): ?string => $keyId === FakeGoogle::KEY_ID ? $pem : null,
        ['https://accounts.google.com', 'accounts.google.com'],
        $audience,
        VERIFIER_NONCE,
    );
}

/**
 * The refusal code, or null when the token was accepted.
 */
function idTokenRefusal(callable $verify): ?string
{
    try {
        $verify();

        return null;
    } catch (SocialProviderException $e) {
        return $e->errorCode;
    }
}

test('a valid token is accepted and its claims returned', function (): void {
    $claims = verifyIdToken(['sub' => 'accepted-subject']);

    expect($claims['sub'])->toBe('accepted-subject')
        ->and($claims['aud'])->toBe(FakeGoogle::CLIENT_ID);
});

test('only RS256 is accepted, so a public key can never be used as a shared secret', function (string $algorithm): void {
    expect(idTokenRefusal(fn () => verifyIdToken([], ['alg' => $algorithm])))->toBe('ID_TOKEN_ALGORITHM');
})->with(['none', 'HS256', 'RS512', 'ES256']);

test('each check refuses with its own code', function (array $claims, array $header, bool $foreignKey, string $code): void {
    expect(idTokenRefusal(fn () => verifyIdToken($claims, $header, $foreignKey ? FakeGoogle::otherKey() : null)))->toBe($code);
})->with([
    'unknown key id' => [[], ['kid' => 'a-key-nobody-published'], false, 'ID_TOKEN_KEY_UNKNOWN'],
    'signed by another key' => [[], [], true, 'ID_TOKEN_SIGNATURE'],
    'another issuer' => [['iss' => 'https://accounts.example.test'], [], false, 'ID_TOKEN_ISSUER'],
    'another audience' => [['aud' => 'other-client'], [], false, 'ID_TOKEN_AUDIENCE'],
    'expired beyond leeway' => [['exp' => time() - 86400, 'iat' => time() - 90000], [], false, 'ID_TOKEN_EXPIRED'],
    'issued in the future' => [['iat' => time() + 86400, 'exp' => time() + 90000], [], false, 'ID_TOKEN_NOT_YET_VALID'],
    'not valid before a future time' => [['nbf' => time() + 86400, 'exp' => time() + 90000], [], false, 'ID_TOKEN_NOT_YET_VALID'],
    'a nonce from another flow' => [['nonce' => 'a-different-nonce'], [], false, 'ID_TOKEN_NONCE'],
    'authorized to another client' => [['azp' => 'other-client'], [], false, 'ID_TOKEN_AUDIENCE'],
    'no expiry' => [['exp' => null], [], false, 'ID_TOKEN_EXPIRED'],
    'an issued-at that is not a time' => [['iat' => 'yesterday'], [], false, 'ID_TOKEN_NOT_YET_VALID'],
    'a not-before that is not a time' => [['nbf' => 'soon'], [], false, 'ID_TOKEN_NOT_YET_VALID'],
    'a subject that is not text' => [['sub' => 12345], [], false, 'ID_TOKEN_SUBJECT'],
    'no subject' => [['sub' => ''], [], false, 'ID_TOKEN_SUBJECT'],
]);

test('a token whose claims were altered after signing is refused', function (): void {
    $published = FakeGoogle::publishedKey();
    $pem = RsaPublicKey::pemFromJwk($published['n'], $published['e']);

    [$header, , $signature] = explode('.', FakeGoogle::idToken(FakeGoogle::claims(VERIFIER_NONCE)));
    $forged = FakeGoogle::base64Url((string) json_encode(FakeGoogle::claims(VERIFIER_NONCE, ['sub' => 'someone-else'])));

    $refusal = idTokenRefusal(fn () => app(IdTokenVerifier::class)->verify(
        $header.'.'.$forged.'.'.$signature,
        static fn (): ?string => $pem,
        ['https://accounts.google.com'],
        FakeGoogle::CLIENT_ID,
        VERIFIER_NONCE,
    ));

    expect($refusal)->toBe('ID_TOKEN_SIGNATURE');
});

test('a token with several audiences must say it was issued to this client', function (): void {
    expect(idTokenRefusal(fn () => verifyIdToken(['aud' => [FakeGoogle::CLIENT_ID, 'other-client'], 'azp' => 'other-client'])))
        ->toBe('ID_TOKEN_AUDIENCE')
        ->and(idTokenRefusal(fn () => verifyIdToken(['aud' => [FakeGoogle::CLIENT_ID, 'other-client'], 'azp' => FakeGoogle::CLIENT_ID])))
        ->toBeNull();
});

test('a token that names no authorized party is accepted when this client is its only audience', function (): void {
    expect(idTokenRefusal(fn () => verifyIdToken(['azp' => null])))->toBeNull();
});

test('a malformed token is refused without reaching a key', function (): void {
    $refusal = idTokenRefusal(fn () => app(IdTokenVerifier::class)->verify(
        'not-a-jwt',
        static fn (): ?string => throw new RuntimeException('No key should have been asked for.'),
        ['https://accounts.google.com'],
        FakeGoogle::CLIENT_ID,
        VERIFIER_NONCE,
    ));

    expect($refusal)->toBe('ID_TOKEN_MALFORMED');
});

test('a JWK becomes the same public key OpenSSL holds', function (): void {
    $published = FakeGoogle::publishedKey();
    $pem = RsaPublicKey::pemFromJwk($published['n'], $published['e']);

    $expected = openssl_pkey_get_details(FakeGoogle::signingKey())['key'] ?? null;

    expect($pem)->toBe($expected);
});

test('a JWK that is not a key is refused', function (): void {
    expect(RsaPublicKey::pemFromJwk('!!!', 'AQAB'))->toBeNull()
        ->and(RsaPublicKey::pemFromJwk('', 'AQAB'))->toBeNull();
});
